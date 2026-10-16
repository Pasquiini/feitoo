<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ProcessingFileError;
use App\Services\Documents\DocxProcessingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\TimeoutExceededException;
use RuntimeException;
use Throwable;

class ProcessDocxToPdfJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $processingUuid, public readonly string $fileUuid)
    {
        $this->timeout = config('documents.timeout') + 20;
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('docx:'.$this->fileUuid))->releaseAfter(10)->expireAfter($this->timeout + 10)];
    }

    public function handle(DocxProcessingService $service): void
    {
        try {
            $service->processFile($this->processingUuid, $this->fileUuid);
        } catch (Throwable) {
            // Failed-job storage must never receive renderer output, paths or document data.
            throw new RuntimeException('Document processing attempt failed.');
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(DocxProcessingService::class)->failFile($this->processingUuid, $this->fileUuid, $exception instanceof TimeoutExceededException ? ProcessingFileError::ConversionTimeout : ProcessingFileError::ConversionFailed);
    }

    public function backoff(): array
    {
        return [10, 30];
    }
}
