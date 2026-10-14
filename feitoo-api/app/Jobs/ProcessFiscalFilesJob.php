<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\FiscalProcessingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ProcessFiscalFilesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public readonly string $processingUuid) {}

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        // Duplicate deliveries are discarded while the original is still working.
        // Lease exceeds timeout and expires before the default retry_after=90.
        return [(new WithoutOverlapping($this->processingUuid))->dontRelease()->expireAfter(75)];
    }

    public function handle(FiscalProcessingService $service): void
    {
        try {
            $service->process($this->processingUuid);
        } catch (Throwable $exception) {
            Log::error('Fiscal batch attempt failed.', ['processing_uuid' => $this->processingUuid, 'exception_type' => $exception::class]);
            // Laravel persists failed-job exceptions: never attach the sensitive original.
            throw new RuntimeException('Fiscal processing attempt failed.');
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(FiscalProcessingService::class)->fail($this->processingUuid);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30];
    }
}
