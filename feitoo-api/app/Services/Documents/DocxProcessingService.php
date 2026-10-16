<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Enums\ProcessingFileError;
use App\Enums\ProcessingFileStatus;
use App\Enums\ProcessingStatus;
use App\Enums\ProcessingTool;
use App\Models\Processing;
use App\Models\ProcessingFile;
use App\Models\User;
use App\Services\PdfResultService;
use App\Services\ProcessingService;
use App\Support\Documents\DocumentException;
use App\Support\Documents\DocxValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class DocxProcessingService
{
    public function processFile(string $uuid, string $fileUuid): void
    {
        $file = DB::transaction(function () use ($uuid, $fileUuid): ?ProcessingFile {
            $batch = Processing::where('uuid', $uuid)->lockForUpdate()->first();
            if (! $this->active($batch)) {
                return null;
            }
            $batch->update(['status' => ProcessingStatus::Processing, 'started_at' => $batch->started_at ?? now()]);
            $file = $batch->files()->where('kind', 'input')->where('uuid', $fileUuid)->whereNull('removed_at')->first();
            if (! $file || in_array($file->status, [ProcessingFileStatus::Completed, ProcessingFileStatus::Failed], true)) {
                $this->finish($batch);

                return null;
            }
            $file->update(['status' => ProcessingFileStatus::Processing]);

            return $file;
        });
        if (! $file) {
            return;
        }
        $disk = Storage::disk('local');
        $directory = 'document-temporary/'.$uuid.'/'.Str::uuid();
        $workspace = $disk->path($directory);
        $pdf = null;
        $error = null;
        try {
            if (! $disk->makeDirectory($directory)) {
                throw new DocumentException(ProcessingFileError::StorageError);
            }
            @chmod($workspace, 0700);
            // Read through storage, verify checksum, never give LO a user-controlled filename.
            $stream = Storage::disk($file->storage_disk)->readStream($file->storage_path);
            if (! is_resource($stream)) {
                throw new DocumentException(ProcessingFileError::StorageError);
            }
            $target = fopen($workspace.'/input.docx', 'wb');
            try {
                if (! is_resource($target) || stream_copy_to_stream($stream, $target, config('documents.max_file_size_kb') * 1024 + 1) !== $file->size) {
                    throw new DocumentException(ProcessingFileError::StorageError);
                }
            } finally {
                fclose($stream);
                if (is_resource($target)) {
                    fclose($target);
                }
            }
            if (! hash_equals($file->checksum, hash_file('sha256', $workspace.'/input.docx'))) {
                throw new DocumentException(ProcessingFileError::StorageError);
            }
            app(DocxValidator::class)->validate($workspace.'/input.docx');
            $output = app(DocxToPdfConverter::class)->convert($workspace.'/input.docx', $workspace);
            if (realpath(dirname($output)) !== realpath($workspace.'/output') || basename($output) !== 'input.pdf') {
                throw new DocumentException(ProcessingFileError::PdfOutputInvalid);
            }
            app(PdfOutputValidator::class)->validate($output);
            $pdf = file_get_contents($output);
            if ($pdf === false) {
                throw new DocumentException(ProcessingFileError::PdfOutputInvalid);
            }
        } catch (DocumentException $exception) {
            $error = $exception->error;
        } catch (Throwable) {
            $error = ProcessingFileError::ConversionFailed;
        } finally {
            if (! $disk->deleteDirectory($directory)) {
                // Do not persist success until cleanup succeeds. TTL purge also owns the prefix.
                $error = ProcessingFileError::StorageError;
            }
        }
        DB::transaction(function () use ($uuid, $fileUuid, $pdf, $error): void {
            $batch = Processing::where('uuid', $uuid)->lockForUpdate()->first();
            if (! $this->active($batch)) {
                return;
            }
            $file = $batch->files()->where('uuid', $fileUuid)->where('kind', 'input')->whereNull('removed_at')->firstOrFail();
            if (in_array($file->status, [ProcessingFileStatus::Completed, ProcessingFileStatus::Failed], true)) {
                return;
            }
            if ($error === null && $pdf !== null) {
                if ($batch->files()->where('kind', 'output')->where('extension', 'pdf')->sum('size') + strlen($pdf) > config('documents.max_total_pdf_bytes')) {
                    $error = ProcessingFileError::PdfOutputInvalid;
                }
            }
            if ($error === null && $pdf !== null) {
                $stem = mb_substr(preg_replace('/[^\pL\pN ._()-]/u', '_', pathinfo($file->original_name, PATHINFO_FILENAME)) ?? 'documento', 0, 150);
                app(PdfResultService::class)->store($batch, $file->uuid.'.pdf', (trim($stem, '. ') ?: 'documento').'.pdf', 'application/pdf', $pdf);
            }
            $file->update(['status' => $error ? ProcessingFileStatus::Failed : ProcessingFileStatus::Completed,
                'processed_at' => now(), 'error_code' => $error, 'error_message' => $error?->message()]);
            $this->finish($batch);
        });
    }

    public function failFile(string $uuid, string $fileUuid, ProcessingFileError $error): void
    {
        DB::transaction(function () use ($uuid, $fileUuid, $error): void {
            $batch = Processing::where('uuid', $uuid)->lockForUpdate()->first();
            if (! $this->active($batch)) {
                return;
            }
            $batch->files()->where('kind', 'input')->where('uuid', $fileUuid)->whereNull('removed_at')
                ->whereIn('status', ['pending', 'processing'])->update(['status' => ProcessingFileStatus::Failed->value,
                    'error_code' => $error->value, 'error_message' => $error->message(), 'processed_at' => now()]);
            $this->finish($batch);
        });
    }

    public function failBatch(string $uuid): void
    {
        $batch = Processing::where('uuid', $uuid)->first();
        foreach ($batch?->files()->where('kind', 'input')->pluck('uuid') ?? [] as $fileUuid) {
            $this->failFile($uuid, $fileUuid, ProcessingFileError::ConverterUnavailable);
        }
    }

    private function active(?Processing $batch): bool
    {
        if (! $batch || $batch->tool !== ProcessingTool::DocxToPdf || ! in_array($batch->status, [ProcessingStatus::Queued, ProcessingStatus::Processing], true)) {
            return false;
        }
        if (! $batch->expires_at?->isFuture()) {
            app(ProcessingService::class)->removeFiles($batch);

            return false;
        }

        return true;
    }

    private function finish(Processing $batch): void
    {
        $inputs = $batch->files()->where('kind', 'input')->whereNull('removed_at');
        $success = (clone $inputs)->where('status', 'completed')->count();
        $failed = (clone $inputs)->where('status', 'failed')->count();
        $batch->update(['processed_files_count' => $success + $failed, 'successful_files_count' => $success, 'failed_files_count' => $failed]);
        if ($success + $failed !== $batch->files_count) {
            return;
        }
        if ($success > 0) {
            app(PdfResultService::class)->generate($batch, 'documents.zip', 'feitoo-documentos-pdf.zip', null);
        }
        User::whereKey($batch->user_id)->lockForUpdate()->first();
        $batch->update(['status' => $success > 0 ? ProcessingStatus::Completed : ProcessingStatus::Failed,
            'completed_at' => now(), 'usage_files_count' => $success]);
    }
}
