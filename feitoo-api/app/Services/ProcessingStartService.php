<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ProcessingFileStatus;
use App\Enums\ProcessingStatus;
use App\Enums\ProcessingTool;
use App\Enums\PurchaseStatus;
use App\Jobs\ProcessDocxToPdfJob;
use App\Jobs\ProcessFiscalFilesJob;
use App\Models\Processing;
use App\Models\User;
use App\Services\Documents\DocxProcessingService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessingStartService
{
    public function start(Processing $processing, User $owner, ?int $freeFilesCount = null, ?array $freeFileIds = null): Processing
    {
        abort_if($processing->tool === ProcessingTool::QuotationGenerator, 409);
        $connection = $processing->tool === ProcessingTool::DocxToPdf ? 'documents' : config('feitoo.processing.queue_connection');
        // A sync/deferred/failover driver could execute heavy work in the HTTP process.
        abort_unless(in_array(config('queue.connections.'.$connection.'.driver'), ['database', 'redis', 'sqs', 'beanstalkd'], true), 503);

        return DB::transaction(function () use ($processing, $owner, $connection, $freeFilesCount, $freeFileIds): Processing {
            $locked = Processing::query()->lockForUpdate()->findOrFail($processing->id);
            abort_unless((int) $locked->user_id === (int) $owner->id, 404);
            abort_if($locked->status === ProcessingStatus::Expired || ! $locked->expires_at?->isFuture(), 410);
            abort_unless($locked->status === ProcessingStatus::Pending, 409);
            abort_unless($locked->files()->where('kind', 'input')->whereNull('removed_at')->exists(), 422, 'Envie ao menos um arquivo antes de iniciar.');
            if ($freeFileIds !== null) {
                abort_unless(count($freeFileIds) > 0 && count($freeFileIds) === count(array_unique($freeFileIds)), 422);
                $freeFilesCount = count($freeFileIds);
            }
            if ($freeFilesCount !== null) {
                $purchase = $locked->purchase()->first();
                abort_if($purchase && ! in_array($purchase->status, [PurchaseStatus::Expired, PurchaseStatus::Refunded], true), 409);
                abort_unless($freeFilesCount > 0 && $freeFilesCount <= $locked->files_count, 422);
                // Legacy callers choose the first N files. New clients submit explicit UUIDs.
                // Surplus physical files remain private until deletion/TTL and are never parsed.
                $selected = $locked->files()->where('kind', 'input')->whereNull('removed_at')->orderBy('id')->limit($freeFilesCount)->pluck('id');
                if ($freeFileIds !== null) {
                    $selected = $locked->files()->where('kind', 'input')->whereNull('removed_at')->whereIn('uuid', $freeFileIds)->pluck('id');
                    abort_unless($selected->count() === $freeFilesCount, 422);
                }
                $locked->files()->where('kind', 'input')->whereNotIn('id', $selected)->update(['status' => ProcessingFileStatus::Removed, 'removed_at' => now()]);
                $locked->files_count = $freeFilesCount;
                $locked->total_size = $locked->files()->where('kind', 'input')->whereNull('removed_at')->sum('size');
                $locked->save();
            }
            abort_if($locked->files_count > config($locked->tool === ProcessingTool::DocxToPdf ? 'documents.max_files' : 'feitoo.uploads.max_processing_files'), 422);
            app(EntitlementService::class)->accept($locked, $owner);
            $locked->update(['status' => ProcessingStatus::Queued]);

            // Enqueue only after commit so a worker cannot observe an uncommitted batch.
            DB::afterCommit(function () use ($locked, $connection): void {
                try {
                    if ($locked->tool === ProcessingTool::DocxToPdf) {
                        foreach ($locked->files()->where('kind', 'input')->whereNull('removed_at')->pluck('uuid') as $fileUuid) {
                            Bus::dispatch((new ProcessDocxToPdfJob($locked->uuid, $fileUuid))->onConnection($connection)->onQueue('documents'));
                        }
                    } else {
                        Bus::dispatch((new ProcessFiscalFilesJob($locked->uuid))->onConnection($connection));
                    }
                } catch (Throwable $exception) {
                    app(EntitlementService::class)->releaseDispatchFailure($locked);
                    if ($locked->tool === ProcessingTool::DocxToPdf) {
                        app(DocxProcessingService::class)->failBatch($locked->uuid);
                    } else {
                        app(FiscalProcessingService::class)->fail($locked->uuid);
                    }
                    Log::error($locked->tool === ProcessingTool::DocxToPdf ? 'Document queue dispatch failed.' : 'Fiscal queue dispatch failed.', ['processing_uuid' => $locked->uuid, 'exception_type' => $exception::class]);
                    abort(503);
                }
            });

            return $locked;
        }, 5);
    }
}
