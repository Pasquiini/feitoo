<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Fiscal\DTO\ParsedFiscalDocument;
use App\Domain\Fiscal\Exceptions\InvalidFiscalXml;
use App\Domain\Fiscal\Exceptions\MissingRequiredFiscalField;
use App\Domain\Fiscal\Exceptions\UnsupportedFiscalDocument;
use App\Domain\Fiscal\Nfe\NfeParser;
use App\Domain\Tools\ProductsFromXml\ProductItem;
use App\Enums\ProcessingFileError;
use App\Enums\ProcessingFileKind;
use App\Enums\ProcessingFileStatus;
use App\Enums\ProcessingStatus;
use App\Enums\ProcessingTool;
use App\Enums\PurchaseStatus;
use App\Jobs\ProcessFiscalFilesJob;
use App\Models\Processing;
use App\Models\ProcessingFile;
use App\Models\ProcessingFileResult;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class FiscalProcessingService
{
    public function __construct(private readonly NfeParser $parser) {}

    public function start(Processing $processing, User $owner, ?int $freeFilesCount = null): Processing
    {
        $connection = config('feitoo.processing.queue_connection');
        // A sync/deferred/failover driver could execute heavy work in the HTTP process.
        abort_unless(in_array(config('queue.connections.'.$connection.'.driver'), ['database', 'redis', 'sqs', 'beanstalkd'], true), 503);

        return DB::transaction(function () use ($processing, $owner, $connection, $freeFilesCount): Processing {
            $locked = Processing::query()->lockForUpdate()->findOrFail($processing->id);
            abort_unless((int) $locked->user_id === (int) $owner->id, 404);
            abort_if($this->expired($locked), 410);
            abort_unless($locked->status === ProcessingStatus::Pending, 409);
            abort_unless($this->inputs($locked)->whereNull('removed_at')->exists(), 422, 'Envie ao menos um XML antes de iniciar.');
            if ($freeFilesCount !== null) {
                $purchase = $locked->purchase()->first();
                abort_if($purchase && ! in_array($purchase->status, [PurchaseStatus::Expired, PurchaseStatus::Refunded], true), 409);
                abort_unless($freeFilesCount > 0 && $freeFilesCount <= $locked->files_count, 422);
                // Explicit user choice of the FIRST N files only. Surplus physical files remain
                // private until deletion/TTL and are never parsed or counted as accepted work.
                $selected = $this->inputs($locked)->whereNull('removed_at')->orderBy('id')->limit($freeFilesCount)->pluck('id');
                $this->inputs($locked)->whereNotIn('id', $selected)->update(['status' => ProcessingFileStatus::Removed, 'removed_at' => now()]);
                $locked->files_count = $freeFilesCount;
                $locked->total_size = $this->inputs($locked)->whereNull('removed_at')->sum('size');
                $locked->save();
            }
            abort_if($locked->files_count > config('feitoo.uploads.max_processing_files'), 422);
            app(EntitlementService::class)->accept($locked, $owner);
            $locked->update(['status' => ProcessingStatus::Queued]);

            // Enqueue only after commit so a worker cannot observe an uncommitted batch.
            DB::afterCommit(function () use ($locked, $connection): void {
                try {
                    Bus::dispatch((new ProcessFiscalFilesJob($locked->uuid))->onConnection($connection));
                } catch (Throwable $exception) {
                    app(EntitlementService::class)->releaseDispatchFailure($locked);
                    $this->fail($locked->uuid);
                    Log::error('Fiscal queue dispatch failed.', ['processing_uuid' => $locked->uuid, 'exception_type' => $exception::class]);
                    abort(503);
                }
            });

            return $locked;
        }, 5);
    }

    public function process(string $uuid): void
    {
        $active = DB::transaction(function () use ($uuid): bool {
            $processing = Processing::query()->where('uuid', $uuid)->lockForUpdate()->first();
            if (! $processing || ! $this->active($processing)) {
                return false;
            }
            if ($this->expired($processing)) {
                app(ProcessingService::class)->removeFiles($processing);

                return false;
            }
            $processing->update(['status' => ProcessingStatus::Processing, 'started_at' => $processing->started_at ?? now()]);

            return true;
        });
        if (! $active) {
            return;
        }
        $processing = Processing::query()->where('uuid', $uuid)->firstOrFail();
        $ids = $this->inputs($processing)->orderBy('id')->pluck('id');
        foreach ($ids as $id) {
            $this->processFile($uuid, (int) $id);
        }
        DB::transaction(function () use ($uuid): void {
            $processing = Processing::query()->where('uuid', $uuid)->lockForUpdate()->first();
            if (! $processing || ! $this->active($processing)) {
                return;
            }
            if ($this->expired($processing)) {
                app(ProcessingService::class)->removeFiles($processing);

                return;
            }
            $this->counts($processing);
            if ($processing->processed_files_count !== $processing->files_count) {
                throw new RuntimeException('Fiscal processing is incomplete.');
            }
            if ($processing->successful_files_count === 0) {
                $processing->update(['status' => ProcessingStatus::Failed, 'completed_at' => now(),
                    'metadata' => ['result_error' => 'no_valid_documents']]);

                return;
            }
            $items = ProcessingFileResult::query()->whereIn('processing_file_id', $this->inputs($processing)->whereNull('removed_at')->select('id'))->sum('items_count');
            if ($items > config('feitoo.uploads.max_processing_items')) {
                $processing->update(['status' => ProcessingStatus::Failed, 'completed_at' => now(),
                    'metadata' => ['result_error' => 'technical_capacity_exceeded']]);

                return;
            }
            app(XmlToExcelResultService::class)->generate($processing);
            if ($this->expired($processing)) {
                app(ProcessingService::class)->removeFiles($processing);

                return;
            }
            $processing->update(['status' => ProcessingStatus::Completed, 'completed_at' => now()]);
        });
    }

    private function processFile(string $uuid, int $id): void
    {
        // Keep the parent locked through read/parse/commit. Purge and deletion use the same lock.
        DB::transaction(function () use ($uuid, $id): void {
            $processing = Processing::query()->where('uuid', $uuid)->lockForUpdate()->first();
            if (! $processing || ! $this->active($processing)) {
                return;
            }
            if ($this->expired($processing)) {
                app(ProcessingService::class)->removeFiles($processing);

                return;
            }
            $file = $this->inputs($processing)->whereKey($id)->lockForUpdate()->first();
            if (! $file || $file->removed_at || in_array($file->status, [ProcessingFileStatus::Completed, ProcessingFileStatus::Failed, ProcessingFileStatus::Removed], true)) {
                return;
            }
            $file->update(['status' => ProcessingFileStatus::Processing]);
            $error = null;
            $document = null;
            try {
                $contents = $this->read($file);
            } catch (Throwable $exception) {
                $error = ProcessingFileError::StorageError;
                $this->logFileFailure($processing, $file, $exception);
            }
            if ($error === null) {
                try {
                    $document = $this->parser->parse($contents, productAnalysis: $processing->tool === ProcessingTool::ProductsFromXml);
                } catch (InvalidFiscalXml) {
                    $error = ProcessingFileError::InvalidXml;
                } catch (UnsupportedFiscalDocument) {
                    $error = ProcessingFileError::UnsupportedDocument;
                } catch (MissingRequiredFiscalField) {
                    $error = ProcessingFileError::MissingRequiredField;
                } catch (Throwable $exception) {
                    $error = ProcessingFileError::ParseError;
                    $this->logFileFailure($processing, $file, $exception);
                }
            }
            if ($document) {
                // DB failures are global and must roll back, not be mislabeled as parse errors.
                $this->saveResult($file, $document);
            }
            $file->update([
                'status' => $error ? ProcessingFileStatus::Failed : ProcessingFileStatus::Completed,
                'processed_at' => now(), 'error_code' => $error, 'error_message' => $error?->message(),
            ]);
            $this->counts($processing);
        });
    }

    private function read(ProcessingFile $file): string
    {
        return app(PrivateProcessingFileReader::class)->read($file);
    }

    private function saveResult(ProcessingFile $file, ParsedFiscalDocument $document): void
    {
        $file->result()->updateOrCreate([], [
            'document_type' => $document->documentType->value, 'model' => $document->model,
            'access_key' => $document->accessKey, 'number' => $document->number, 'series' => $document->series,
            'issued_at' => $document->issuedAt->format($document->identification->issuedAtHasTime ? 'c' : 'Y-m-d'),
            'issued_at_has_time' => $document->identification->issuedAtHasTime,
            'issuer_document' => $this->summaryText($document->issuer->cnpj ?? $document->issuer->cpf, 32),
            'issuer_name' => $this->summaryText($document->issuer->name),
            'recipient_document' => $this->summaryText($document->recipient?->cnpj ?? $document->recipient?->cpf, 32),
            'recipient_name' => $this->summaryText($document->recipient?->name),
            'items_count' => count($document->items), 'total_value' => $document->totals?->vNF,
            'products_items' => $file->processing->tool === ProcessingTool::ProductsFromXml
                ? array_map(fn ($item) => get_object_vars($item), ProductItem::fromDocument($document)) : null,
            'items_preview' => array_map(fn ($item) => [
                'code' => $this->summaryText($item->productCode),
                'product' => $this->summaryText($item->description),
                'quantity' => $item->commercialQuantity, 'unit_value' => $item->commercialUnitPrice,
                'total_value' => $item->productTotal,
            ], array_slice($document->items, 0, ProcessingPreviewService::ITEMS_LIMIT)),
        ]);
    }

    private function summaryText(?string $value, int $limit = 255): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $limit);
    }

    private function counts(Processing $processing): void
    {
        $success = $this->inputs($processing)->where('status', ProcessingFileStatus::Completed->value)->count();
        $failed = $this->inputs($processing)->where('status', ProcessingFileStatus::Failed->value)->count();
        $processing->update(['processed_files_count' => $success + $failed, 'successful_files_count' => $success, 'failed_files_count' => $failed]);
    }

    /** @return HasMany<ProcessingFile, Processing> */
    private function inputs(Processing $processing): HasMany
    {
        return $processing->files()->where('kind', ProcessingFileKind::Input->value);
    }

    private function expired(Processing $processing): bool
    {
        return $processing->status === ProcessingStatus::Expired || $processing->expires_at?->lessThanOrEqualTo(now()) === true;
    }

    private function active(Processing $processing): bool
    {
        return $processing->user_id !== null && in_array($processing->status, [ProcessingStatus::Queued, ProcessingStatus::Processing], true);
    }

    public function fail(string $uuid): void
    {
        DB::transaction(function () use ($uuid): void {
            $processing = Processing::query()->where('uuid', $uuid)->lockForUpdate()->first();
            if (! $processing || ! $this->active($processing)) {
                return;
            }
            if ($this->expired($processing)) {
                app(ProcessingService::class)->removeFiles($processing);

                return;
            }
            $this->counts($processing);
            $processing->update(['status' => ProcessingStatus::Failed, 'completed_at' => now()]);
        });
    }

    private function logFileFailure(Processing $processing, ProcessingFile $file, Throwable $exception): void
    {
        Log::warning('Fiscal file failed.', ['processing_uuid' => $processing->uuid, 'file_uuid' => $file->uuid, 'exception_type' => $exception::class]);
    }
}
