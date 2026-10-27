<?php

namespace App\Jobs;

use App\Services\Quotations\QuotationExecutionRunner;
use App\Services\Quotations\QuotationGenerationService;
use App\Services\Quotations\QuotationPdfException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GenerateQuotationPdfJob implements ShouldQueue
{
    use Queueable;

    // Attempts are persisted/fenced in quotation_generations, not inferred from queue deliveries.
    public int $tries = 0;

    public int $timeout = 60;

    public function __construct(public readonly string $generationUuid)
    {
        $this->onConnection('quotations')->onQueue('quotations');
    }

    public function handle(QuotationGenerationService $service, QuotationExecutionRunner $runner): void
    {
        $slot = null;
        $claimed = null;
        try {
            $slot = $runner->acquireSlot();
            if (! $slot) {
                $this->release(5);

                return;
            }
            $claimed = $service->claim($this->generationUuid);
            if (! $claimed) {
                return;
            }
            $pdf = $runner->render($claimed);
            $service->complete($claimed, $pdf);
        } catch (Throwable $error) {
            if ($claimed) {
                $retry = $service->failAttempt($claimed, $error instanceof QuotationPdfException ? $error->reason : 'storage_unavailable');
                if ($retry) {
                    $this->release(5);
                }
            } else {
                // No financial mutation on slot/storage failure. The pending deadline still bounds the reservation.
                $this->release(10);
            }
        } finally {
            if (is_resource($slot)) {
                flock($slot, LOCK_UN);
                fclose($slot);
            }
        }
    }
}
