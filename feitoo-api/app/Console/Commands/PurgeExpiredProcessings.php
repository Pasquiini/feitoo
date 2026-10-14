<?php

namespace App\Console\Commands;

use App\Enums\ProcessingStatus;
use App\Models\Processing;
use App\Services\ProcessingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class PurgeExpiredProcessings extends Command
{
    protected $signature = 'feitoo:purge-expired-processings';

    protected $description = 'Remove private files from expired processings while retaining technical records';

    public function handle(ProcessingService $service): int
    {
        $failures = 0;
        Processing::query()->where('expires_at', '<=', now())
            ->where('status', '!=', ProcessingStatus::Expired->value)
            ->chunkById(100, function ($processings) use ($service, &$failures): void {
                foreach ($processings as $processing) {
                    try {
                        $service->removeFiles($processing, onlyIfExpired: true);
                    } catch (Throwable) {
                        $failures++;
                        Log::warning('Expired processing cleanup failed.', ['processing_uuid' => $processing->uuid]);
                        $this->warn('Cleanup failed: '.$processing->uuid);
                    }
                }
            });

        $this->info('Expired processing cleanup finished. Failures: '.$failures);

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
