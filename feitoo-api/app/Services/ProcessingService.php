<?php

namespace App\Services;

use App\Enums\ProcessingFileError;
use App\Enums\ProcessingFileKind;
use App\Enums\ProcessingFileStatus;
use App\Enums\ProcessingStatus;
use App\Enums\ProcessingTool;
use App\Enums\PurchaseStatus;
use App\Exceptions\BillingException;
use App\Models\Processing;
use App\Models\ProcessingFileResult;
use App\Models\User;
use App\Rules\SafeXml;
use App\Services\Quotations\QuotationGenerationService;
use App\Support\Documents\DocumentException;
use App\Support\Documents\DocxValidator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class ProcessingService
{
    /** @param list<UploadedFile> $uploads */
    public function upload(Processing $processing, array $uploads, User $owner): Processing
    {
        abort_if($processing->tool === ProcessingTool::QuotationGenerator, 409);
        $written = [];
        $disk = config('feitoo.processing.disk');

        try {
            return DB::transaction(function () use ($processing, $uploads, $disk, $owner, &$written): Processing {
                $locked = Processing::query()->lockForUpdate()->findOrFail($processing->id);
                abort_unless((int) $locked->user_id === (int) $owner->id, 404);
                abort_if($locked->status === ProcessingStatus::Expired || $locked->expires_at?->lessThanOrEqualTo(now()), 410, 'Processamento expirado.');
                abort_unless($locked->status === ProcessingStatus::Pending, 409, 'Processamento não aceita novos arquivos.');

                if ($locked->purchase()->exists()) {
                    throw new BillingException('purchase_volume_locked', 'O lote vinculado a uma compra não aceita novos arquivos.');
                }
                $documents = $locked->tool === ProcessingTool::DocxToPdf;
                if ($locked->files_count + count($uploads) > config($documents ? 'documents.max_files' : 'feitoo.uploads.max_processing_files')) {
                    throw ValidationException::withMessages(['files' => 'Quantidade máxima de arquivos por processamento excedida.']);
                }
                if ($locked->total_size + array_sum(array_map(fn ($file) => $file->getSize(), $uploads)) > config($documents ? 'documents.max_total_size_kb' : 'feitoo.uploads.max_total_size_kb') * 1024) {
                    throw ValidationException::withMessages(['files' => 'O lote excede o tamanho total aceito.']);
                }

                $locked->update(['status' => ProcessingStatus::Uploading]);
                foreach ($uploads as $upload) {
                    $uuid = (string) Str::uuid();
                    $extension = $documents ? 'docx' : 'xml';
                    $name = $uuid.'.'.$extension;
                    $directory = 'processings/'.$locked->uuid.'/'.ProcessingFileKind::Input->value;
                    $path = $directory.'/'.$name;
                    // Track the intended path before writing, including a partially failed write.
                    $written[] = $path;
                    $checksum = hash_file('sha256', $upload->getRealPath());
                    $rejected = false;
                    $documentError = null;
                    if ($documents) {
                        try {
                            app(DocxValidator::class)->validate($upload->getRealPath(), $upload->getClientOriginalExtension(), $upload->getMimeType());
                        } catch (DocumentException $exception) {
                            $documentError = $exception->error;
                            $rejected = true;
                        }
                    }
                    if ($locked->tool === ProcessingTool::XmlAnalyzer) {
                        (new SafeXml)->validate('files', $upload, function () use (&$rejected): void {
                            $rejected = true;
                        });
                    }
                    if ($checksum === false || (! $rejected && $upload->storeAs($directory, $name, ['disk' => $disk, 'visibility' => 'private']) === false)) {
                        throw new RuntimeException('Processing storage write failed.');
                    }

                    $originalName = mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $upload->getClientOriginalName()))) ?? '', 0, 200);
                    $locked->files()->create([
                        'uuid' => $uuid,
                        'kind' => ProcessingFileKind::Input,
                        'original_name' => $originalName,
                        'stored_name' => $name,
                        'storage_disk' => $disk,
                        'storage_path' => $path,
                        'mime_type' => $upload->getMimeType(),
                        'extension' => $extension,
                        'size' => $upload->getSize(),
                        'checksum' => $checksum,
                        ...($rejected ? ['status' => ProcessingFileStatus::Failed, 'processed_at' => now(),
                            'error_code' => $documentError ?? ProcessingFileError::InvalidXml,
                            'error_message' => $documentError?->message() ?? 'Conteúdo XML inválido, formato incompatível ou recursos proibidos. Arquivo não armazenado.'] : []),
                        'expires_at' => $locked->expires_at,
                    ]);
                    $locked->files_count++;
                    $locked->total_size += $upload->getSize();
                }

                $locked->status = ProcessingStatus::Pending;
                $locked->save();

                return $locked;
            });
        } catch (Throwable $exception) {
            foreach ($written as $path) {
                try {
                    if (! Storage::disk($disk)->delete($path)) {
                        throw new RuntimeException('Processing compensation failed.');
                    }
                } catch (Throwable) {
                    // Purge also deletes the generated processing prefix, including orphan files.
                    Log::warning('Processing upload rollback cleanup failed.', ['processing_uuid' => $processing->uuid]);
                }
            }

            throw $exception;
        }
    }

    public function removeFiles(Processing $processing, bool $onlyIfExpired = false, ?User $owner = null): Processing
    {
        if ($processing->tool === ProcessingTool::QuotationGenerator) {
            return app(QuotationGenerationService::class)->purge($processing, $onlyIfExpired, $owner);
        }

        return DB::transaction(function () use ($processing, $onlyIfExpired, $owner): Processing {
            $locked = Processing::query()->lockForUpdate()->findOrFail($processing->id);
            if ($owner) {
                abort_unless((int) $locked->user_id === (int) $owner->id, 404);
            }
            if ($onlyIfExpired && $locked->status === ProcessingStatus::Pending && $locked->expires_at?->isPast()
                && $locked->purchase
                && in_array($locked->purchase->status, [PurchaseStatus::Pending, PurchaseStatus::Failed, PurchaseStatus::Expired], true)) {
                // Cleanup uses GET reconciliation; communication errors retain files for the next scheduled attempt.
                app(PurchaseService::class)->refresh($locked);
                $locked->refresh();
            }
            if ($onlyIfExpired && (! $locked->expires_at || $locked->expires_at->isFuture())) {
                return $locked;
            }

            // Remove the whole generated prefix: includes any orphan from interrupted uploads.
            if (! Storage::disk('local')->deleteDirectory('pdf-temporary/'.$locked->uuid)) {
                throw new RuntimeException('PDF temporary storage removal failed.');
            }
            if ($locked->tool === ProcessingTool::DocxToPdf && ! Storage::disk('local')->deleteDirectory('document-temporary/'.$locked->uuid)) {
                throw new RuntimeException('Document temporary storage removal failed.');
            }
            $disks = $locked->files()->pluck('storage_disk')->push(config('feitoo.processing.disk'))->unique();
            foreach ($disks as $disk) {
                if (! Storage::disk($disk)->deleteDirectory('processings/'.$locked->uuid)) {
                    throw new RuntimeException('Processing storage removal failed.');
                }
            }
            if ($locked->tool === ProcessingTool::XmlToPdf && ! Storage::disk('local')->deleteDirectory('danfe-temporary/'.$locked->uuid)) {
                throw new RuntimeException('DANFE temporary storage removal failed.');
            }
            if ($locked->tool === ProcessingTool::XmlOrganizer && ! Storage::disk('local')->deleteDirectory('organizer-temporary/'.$locked->uuid)) {
                throw new RuntimeException('Organizer temporary storage removal failed.');
            }
            if ($locked->tool === ProcessingTool::XmlAnalyzer && ! Storage::disk('local')->deleteDirectory('analyzer-temporary/'.$locked->uuid)) {
                throw new RuntimeException('Analyzer temporary storage removal failed.');
            }

            ProcessingFileResult::query()->whereIn('processing_file_id', $locked->files()->select('id'))->delete();
            $metadata = $locked->metadata ?? [];
            unset($metadata['products_summary'], $metadata['products_preview'], $metadata['products_items_preview']);
            unset($metadata['organizer_files']);
            unset($metadata['analyzer_summary'], $metadata['analyzer_audit'], $metadata['analyzer_bytes'], $metadata['analyzer_items']);
            $locked->metadata = $metadata;
            $locked->files()->whereNull('removed_at')->update([
                'removed_at' => now(), 'original_name' => null, 'status' => ProcessingFileStatus::Removed->value,
                'error_code' => null, 'error_message' => null,
            ]);
            $locked->status = ProcessingStatus::Expired;
            if (in_array($locked->tool, [ProcessingTool::DocxToPdf, ProcessingTool::XmlToPdf, ProcessingTool::XmlOrganizer, ProcessingTool::XmlAnalyzer], true) && in_array($locked->getOriginal('status'), [ProcessingStatus::Queued, ProcessingStatus::Processing], true)) {
                User::query()->whereKey($locked->user_id)->lockForUpdate()->first();
                $locked->usage_files_count = 0;
            }
            if (! $locked->expires_at || $locked->expires_at->isFuture()) {
                $locked->expires_at = now();
            }
            $locked->save();

            return $locked;
        });
    }
}
