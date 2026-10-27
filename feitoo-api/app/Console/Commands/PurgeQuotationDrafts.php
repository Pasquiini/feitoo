<?php

namespace App\Console\Commands;

use App\Models\QuotationDraft;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PurgeQuotationDrafts extends Command
{
    protected $signature = 'feitoo:purge-quotation-drafts';

    protected $description = 'Remove expired commercial drafts and orphaned private logos';

    public function handle(): int
    {
        $disk = Storage::disk('quotation_drafts');
        QuotationDraft::where('expires_at', '<=', now())->select('id')->chunkById(100, function ($rows) use ($disk) {
            foreach ($rows as $row) {
                DB::transaction(function () use ($row, $disk) {
                    $draft = QuotationDraft::whereKey($row->id)->lockForUpdate()->first();
                    if (! $draft || $draft->expires_at->isFuture()) {
                        return;
                    }
                    if ($draft->logo_path) {
                        $disk->delete($draft->logo_path);
                    }
                    $draft->delete();
                });
            }
        });
        // Grace protects files written by an uncommitted upload; only this dedicated disk is scanned.
        foreach ($disk->allFiles() as $path) {
            if ($disk->lastModified($path) < now()->subHour()->timestamp && ! QuotationDraft::where('logo_path', $path)->exists()) {
                $disk->delete($path);
            }
        }
        $this->info('Commercial draft cleanup completed.');

        return self::SUCCESS;
    }
}
