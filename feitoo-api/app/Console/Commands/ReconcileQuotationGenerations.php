<?php

namespace App\Console\Commands;

use App\Models\ProcessingFile;
use App\Models\QuotationGeneration;
use App\Services\Quotations\QuotationGenerationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ReconcileQuotationGenerations extends Command
{
    protected $signature = 'feitoo:reconcile-quotation-generations';

    protected $description = 'Fence abandoned quotation attempts and clean private orphan workspaces';

    public function handle(QuotationGenerationService $service): int
    {
        QuotationGeneration::where('consumption_status', 'reserved')->where(function ($query) {
            $query->where('pending_expires_at', '<=', now())->orWhere('lease_expires_at', '<=', now());
        })->select('id', 'uuid')->chunkById(100, function ($rows) use ($service) {
            foreach ($rows as $row) {
                $service->reconcile($row->uuid);
            }
        });
        $disk = Storage::disk('quotation_execution');
        foreach ($disk->directories() as $uuid) {
            if (! Str::isUuid($uuid)) {
                continue;
            }
            foreach ($disk->directories($uuid) as $directory) {
                $token = basename($directory);
                if (! Str::isUuid($token)) {
                    continue;
                }
                $files = $disk->allFiles($directory);
                $recent = array_filter($files, fn ($file) => $disk->lastModified($file) > now()->subMinutes(5)->timestamp);
                $active = QuotationGeneration::where('uuid', $uuid)->where('attempt_token', $token)->where('lease_expires_at', '>', now())->exists();
                if (! $active && ! $recent) {
                    $disk->deleteDirectory($directory);
                }
            }
        }
        $source = Storage::disk('quotation_generations');
        foreach ($source->directories('processings') as $directory) {
            $uuid = basename($directory);
            if (! Str::isUuid($uuid)) {
                continue;
            }
            $generation = QuotationGeneration::where('uuid', $uuid)->first();
            $files = $source->allFiles($directory);
            $recent = array_filter($files, fn ($file) => $source->lastModified($file) > now()->subHour()->timestamp);
            if ((! $generation || $generation->purged_at) && ! $recent) {
                $source->deleteDirectory($directory);
            } elseif ($generation) {
                // Failed/fenced output writes are not allowed to remain indefinitely after a successful issuance.
                foreach ($source->directories($directory.'/attempts') as $attemptPath) {
                    foreach ($source->allFiles($attemptPath) as $path) {
                        if ($source->lastModified($path) < now()->subMinutes(5)->timestamp
                            && ! ProcessingFile::where('storage_disk', 'quotation_generations')->where('storage_path', $path)->whereNull('removed_at')->exists()
                            && $generation->attempt_token !== basename($attemptPath)) {
                            $source->delete($path);
                        }
                    }
                }
            }
        }
        $this->info('Quotation reconciliation completed.');

        return self::SUCCESS;
    }
}
