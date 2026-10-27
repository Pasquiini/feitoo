<?php

namespace App\Services\Quotations;

use App\Domain\Quotations\Quotation;
use App\Domain\Quotations\QuotationValidationException;
use App\Enums\ProcessingFileKind;
use App\Enums\ProcessingFileStatus;
use App\Enums\ProcessingStatus;
use App\Enums\ProcessingTool;
use App\Exceptions\QuotationGenerationException;
use App\Jobs\GenerateQuotationPdfJob;
use App\Models\Processing;
use App\Models\QuotationDraft;
use App\Models\QuotationGeneration;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class QuotationGenerationService
{
    // All commercial writers use User -> Processing -> Generation -> Quota. Draft locks only occur at creation.
    public function start(User $owner, string $draftUuid, int $revision, string $key, ?string $source = null): Processing
    {
        $hash = hash('sha256', 'create|'.$draftUuid.'|'.$revision.($source === null ? '' : '|'.$source));
        $written = [];
        try {
            return DB::transaction(function () use ($owner, $draftUuid, $revision, $key, $hash, $source, &$written) {
                User::whereKey($owner->id)->lockForUpdate()->firstOrFail();
                if ($replay = $this->replay($owner->id, $key, $hash)) {
                    return $replay;
                }
                $existing = QuotationGeneration::where('user_id', $owner->id)->where('draft_uuid', $draftUuid)->where('draft_revision', $revision)->lockForUpdate()->first();
                if ($existing) {
                    $this->remember($owner->id, $key, $hash, $existing->id);

                    return Processing::findOrFail($existing->processing_id);
                }
                $this->available();
                $draft = QuotationDraft::where('user_id', $owner->id)->where('uuid', $draftUuid)->lockForUpdate()->firstOrFail();
                if ($draft->expires_at->lessThanOrEqualTo(now())) {
                    throw new QuotationGenerationException('draft_expired', 410);
                }
                if ($draft->revision !== $revision) {
                    throw new QuotationGenerationException('draft_revision_conflict');
                }
                try {
                    $input = QuotationDraftDocument::canonical($draft->payload);
                    $document = Quotation::fromArray($input);
                } catch (QuotationValidationException) {
                    throw new QuotationGenerationException('quotation_invalid', 422);
                }
                $this->assertNoActive($owner->id);
                $date = now(config('quotations.timezone'))->toDateString();
                $commercial = app(QuotationCommerce::class)->reserve($owner, $source);
                $quota = $commercial['source'] === 'free' ? $this->reserve($owner->id, $date) : $this->dailyBucket($owner->id, $date);
                $deadline = now()->addMinutes(config('quotations.pending_minutes'));
                $processing = Processing::create(['user_id' => $owner->id, 'tool' => ProcessingTool::QuotationGenerator,
                    'status' => ProcessingStatus::Queued, 'files_count' => 1, 'expires_at' => $deadline,
                    'entitlement_source' => 'quotation_'.$commercial['source'], 'usage_files_count' => 0]);
                $disk = Storage::disk('quotation_generations');
                $prefix = 'processings/'.$processing->uuid;
                $written[] = $prefix;
                $snapshot = ['schema_version' => 1, 'document' => $input, 'totals' => (array) $document->totals];
                $bytes = Crypt::encryptString(json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
                $snapshotPath = $prefix.'/input/snapshot.enc';
                $disk->put($snapshotPath, $bytes);
                $logoPath = null;
                if ($draft->logo_path) {
                    $logo = Storage::disk('quotation_drafts')->get($draft->logo_path);
                    if (! hash_equals($draft->logo_checksum, hash('sha256', $logo))) {
                        throw new QuotationGenerationException('logo_unavailable', 422);
                    }
                    $logoPath = $prefix.'/assets/logo.png';
                    $disk->put($logoPath, $logo);
                }
                $generation = QuotationGeneration::create(['uuid' => $processing->uuid, 'user_id' => $owner->id,
                    'processing_id' => $processing->id, 'draft_uuid' => $draftUuid, 'draft_revision' => $revision,
                    ...$commercial, 'quota_id' => $quota->id, 'reservation_date' => $date, 'snapshot_path' => $snapshotPath,
                    'snapshot_checksum' => hash('sha256', $bytes), 'logo_path' => $logoPath,
                    'logo_checksum' => $draft->logo_checksum, 'renderer_version' => config('quotations.renderer_version'),
                    'pending_expires_at' => $deadline]);
                $processing->files()->create(['kind' => ProcessingFileKind::Input, 'stored_name' => 'snapshot.enc',
                    'original_name' => null, 'storage_disk' => 'quotation_generations', 'storage_path' => $snapshotPath,
                    'mime_type' => 'application/octet-stream', 'extension' => 'enc', 'size' => strlen($bytes),
                    'checksum' => $generation->snapshot_checksum, 'expires_at' => $deadline]);
                $processing->update(['total_size' => strlen($bytes)]);
                $this->remember($owner->id, $key, $hash, $generation->id);
                $this->enqueue($processing->uuid);

                return $processing;
            }, 5);
        } catch (Throwable $error) {
            foreach ($written as $prefix) {
                // Only compensate uncommitted generated prefixes; never touch another tool/draft.
                if (! QuotationGeneration::where('uuid', basename($prefix))->exists()) {
                    try {
                        Storage::disk('quotation_generations')->deleteDirectory($prefix);
                    } catch (Throwable) {
                    }
                }
            }
            throw $error;
        }
    }

    public function retry(User $owner, string $uuid, int $attempt, string $key): Processing
    {
        $hash = hash('sha256', 'retry|'.$uuid.'|'.$attempt);

        return DB::transaction(function () use ($owner, $uuid, $attempt, $key, $hash) {
            User::whereKey($owner->id)->lockForUpdate()->firstOrFail();
            if ($replay = $this->replay($owner->id, $key, $hash)) {
                return $replay;
            }
            $processing = Processing::where('user_id', $owner->id)->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            $generation = QuotationGeneration::where('processing_id', $processing->id)->lockForUpdate()->firstOrFail();
            if ($generation->purged_at || $generation->pending_expires_at->lessThanOrEqualTo(now())) {
                throw new QuotationGenerationException('generation_expired', 410);
            }
            if ($processing->status !== ProcessingStatus::Failed || $generation->attempt !== $attempt || $generation->consumption_status !== 'released') {
                throw new QuotationGenerationException('generation_revision_conflict');
            }
            if (! in_array($generation->error_code, ['render_failed', 'execution_timeout', 'execution_failed', 'worker_abandoned', 'storage_unavailable'], true)) {
                throw new QuotationGenerationException('generation_not_retryable', 422);
            }
            $this->available();
            $this->assertNoActive($owner->id);
            // Never reclassify the original reservation to today's date, including a manual retry.
            app(QuotationCommerce::class)->reserve($owner, $generation->source, $generation);
            if ($generation->source === 'free') {
                $this->reserve($owner->id, $generation->reservation_date);
            }
            $generation->update(['consumption_status' => 'reserved', 'error_code' => null, 'attempt_token' => null, 'lease_expires_at' => null]);
            $processing->update(['status' => ProcessingStatus::Queued, 'completed_at' => null, 'failed_files_count' => 0]);
            $this->remember($owner->id, $key, $hash, $generation->id);
            $this->enqueue($uuid);

            return $processing;
        }, 5);
    }

    public function entitlements(User $owner): array
    {
        $local = now(config('quotations.timezone'));
        $quota = DB::table('quotation_daily_quotas')->where('user_id', $owner->id)->where('reservation_date', $local->toDateString())->first();
        $active = QuotationGeneration::where('user_id', $owner->id)->where('consumption_status', 'reserved')->latest('id')->first();
        $latest = Processing::where('user_id', $owner->id)->where('tool', ProcessingTool::QuotationGenerator->value)
            ->where('expires_at', '>', now())->latest('id')->value('uuid');

        $commercial = app(QuotationCommerce::class)->summary($owner);

        return [...$commercial, 'source' => $commercial['pro']['active'] ? 'pro' : 'free', 'policy' => 'explicit_source', 'daily_limit' => 1,
            'reserved' => (int) ($quota->reserved ?? 0), 'consumed' => (int) ($quota->consumed ?? 0),
            'remaining' => max(0, 1 - ($quota->reserved ?? 0) - ($quota->consumed ?? 0)),
            'resets_at' => $local->copy()->addDay()->startOfDay()->toIso8601String(),
            'enabled' => (bool) config('quotations.enabled'), 'active_processing' => $active?->uuid,
            'latest_processing' => $latest];
    }

    public function claim(string $uuid): ?QuotationGeneration
    {
        return $this->locked($uuid, function ($processing, $generation) {
            if ($generation->consumption_status !== 'reserved' || $processing->status !== ProcessingStatus::Queued || $generation->purged_at) {
                return null;
            }
            if ($generation->pending_expires_at->lessThanOrEqualTo(now())) {
                $this->terminal($processing, $generation, 'execution_expired');

                return null;
            }
            $generation->update(['attempt' => $generation->attempt + 1, 'attempt_token' => (string) Str::uuid(),
                'lease_expires_at' => now()->addSeconds(config('quotations.lease_seconds')), 'error_code' => null]);
            $processing->update(['status' => ProcessingStatus::Processing, 'started_at' => $processing->started_at ?? now()]);

            return $generation;
        });
    }

    public function complete(QuotationGeneration $claimed, QuotationPdf $pdf): bool
    {
        $path = 'processings/'.$claimed->uuid.'/attempts/'.$claimed->attempt_token.'/'.Str::uuid().'.pdf';
        Storage::disk('quotation_generations')->put($path, $pdf->bytes);
        try {
            $published = $this->locked($claimed->uuid, function ($processing, $generation) use ($claimed, $pdf, $path) {
                if (! $this->current($processing, $generation, $claimed->attempt_token)) {
                    return false;
                }
                if (app(QuotationCommerce::class)->blocked($generation)) {
                    $this->terminal($processing, $generation, 'credits_revoked');

                    return false;
                }
                $expires = now()->addMinutes(config('quotations.result_minutes'));
                $processing->files()->create(['kind' => ProcessingFileKind::Output, 'status' => ProcessingFileStatus::Completed,
                    'stored_name' => 'result.pdf', 'original_name' => 'orcamento.pdf', 'storage_disk' => 'quotation_generations',
                    'storage_path' => $path, 'mime_type' => 'application/pdf', 'extension' => 'pdf', 'size' => strlen($pdf->bytes),
                    'checksum' => $pdf->sha256, 'expires_at' => $expires, 'processed_at' => now()]);
                $processing->files()->where('kind', 'input')->update(['status' => ProcessingFileStatus::Completed->value, 'processed_at' => now(), 'expires_at' => $expires]);
                $this->settle($generation, 'consumed');
                $generation->update(['attempt_token' => null, 'lease_expires_at' => null]);
                $processing->update(['status' => ProcessingStatus::Completed, 'completed_at' => now(), 'expires_at' => $expires,
                    'processed_files_count' => 1, 'successful_files_count' => 1, 'failed_files_count' => 0]);

                return true;
            });
            if (! $published) {
                Storage::disk('quotation_generations')->delete($path);
            }

            return (bool) $published;
        } catch (Throwable $error) {
            if (! DB::table('processing_files')->where('storage_disk', 'quotation_generations')->where('storage_path', $path)->exists()) {
                Storage::disk('quotation_generations')->delete($path);
            }
            throw $error;
        }
    }

    public function failAttempt(QuotationGeneration $claimed, string $reason): bool
    {
        return (bool) $this->locked($claimed->uuid, function ($processing, $generation) use ($claimed, $reason) {
            if (! $this->current($processing, $generation, $claimed->attempt_token)) {
                return false;
            }
            $retryable = in_array($reason, ['render_failed', 'execution_timeout', 'execution_failed', 'storage_unavailable'], true);
            if ($retryable && $generation->attempt < config('quotations.max_attempts')) {
                $generation->update(['attempt_token' => null, 'lease_expires_at' => null, 'error_code' => $reason]);
                $processing->update(['status' => ProcessingStatus::Queued]);

                return true;
            }
            $this->terminal($processing, $generation, $reason);

            return false;
        });
    }

    public function reconcile(string $uuid): void
    {
        $this->locked($uuid, function ($processing, $generation) use ($uuid) {
            if ($generation->consumption_status !== 'reserved') {
                return;
            }
            if ($generation->pending_expires_at->lessThanOrEqualTo(now())) {
                $this->terminal($processing, $generation, 'execution_expired');
            } elseif ($processing->status === ProcessingStatus::Processing && $generation->lease_expires_at?->lessThanOrEqualTo(now())) {
                // Fence the old worker before releasing quota or publishing a replacement job.
                if ($generation->attempt >= config('quotations.max_attempts')) {
                    $this->terminal($processing, $generation, 'worker_abandoned');
                } else {
                    $generation->update(['attempt_token' => null, 'lease_expires_at' => null, 'error_code' => 'worker_abandoned']);
                    $processing->update(['status' => ProcessingStatus::Queued]);
                    $this->enqueue($uuid);
                }
            }
        });
    }

    public function purge(Processing $processing, bool $onlyIfExpired = false, ?User $owner = null): Processing
    {
        $result = $this->locked($processing->uuid, function ($locked, $generation) use ($onlyIfExpired, $owner) {
            if ($owner && (int) $locked->user_id !== (int) $owner->id) {
                abort(404);
            }
            if ($onlyIfExpired && $locked->expires_at?->isFuture()) {
                return $locked;
            }
            $this->settle($generation, 'released'); // Consumed issuances are deliberately untouched.
            $generation->update(['attempt_token' => null, 'lease_expires_at' => null, 'purged_at' => now(), 'snapshot_path' => null, 'logo_path' => null]);
            Storage::disk('quotation_generations')->deleteDirectory('processings/'.$locked->uuid);
            // A live subprocess has an independent private workspace and is fenced from publication.
            $locked->files()->whereNull('removed_at')->update(['removed_at' => now(), 'original_name' => null,
                'status' => ProcessingFileStatus::Removed->value]);
            $locked->update(['status' => ProcessingStatus::Expired, 'expires_at' => now()]);

            return $locked;
        });
        if ($result) {
            return $result;
        }

        // Account removal can cascade the commercial ledger while technical Processing rows remain.
        return DB::transaction(function () use ($processing, $onlyIfExpired, $owner) {
            $locked = Processing::whereKey($processing->id)->lockForUpdate()->firstOrFail();
            if ($owner && (int) $locked->user_id !== (int) $owner->id) {
                abort(404);
            }
            if ($onlyIfExpired && $locked->expires_at?->isFuture()) {
                return $locked;
            }
            if (QuotationGeneration::where('processing_id', $locked->id)->exists()) {
                throw new QuotationGenerationException('quota_inconsistent', 503);
            }
            Storage::disk('quotation_generations')->deleteDirectory('processings/'.$locked->uuid);
            $locked->files()->whereNull('removed_at')->update(['removed_at' => now(), 'original_name' => null, 'status' => ProcessingFileStatus::Removed->value]);
            $locked->update(['status' => ProcessingStatus::Expired, 'expires_at' => now()]);

            return $locked;
        });
    }

    private function locked(string $uuid, callable $action): mixed
    {
        $ownerId = QuotationGeneration::where('uuid', $uuid)->value('user_id');
        if (! $ownerId) {
            return null;
        }

        return DB::transaction(function () use ($ownerId, $uuid, $action) {
            if (! User::whereKey($ownerId)->lockForUpdate()->first()) {
                return null;
            }
            $processing = Processing::where('uuid', $uuid)->lockForUpdate()->first();
            $generation = QuotationGeneration::where('uuid', $uuid)->lockForUpdate()->first();
            if (! $processing || ! $generation) {
                return null;
            }

            return $action($processing, $generation);
        }, 5);
    }

    private function current(Processing $processing, QuotationGeneration $generation, ?string $token): bool
    {
        return $token && $generation->attempt_token === $token && $generation->consumption_status === 'reserved'
            && $processing->status === ProcessingStatus::Processing && ! $generation->purged_at
            && $generation->lease_expires_at?->isFuture() && $generation->pending_expires_at->isFuture();
    }

    private function terminal(Processing $processing, QuotationGeneration $generation, string $reason): void
    {
        $this->settle($generation, 'released');
        $generation->update(['error_code' => $reason, 'attempt_token' => null, 'lease_expires_at' => null]);
        $processing->update(['status' => ProcessingStatus::Failed, 'completed_at' => now(), 'processed_files_count' => 1,
            'successful_files_count' => 0, 'failed_files_count' => 1]);
    }

    private function settle(QuotationGeneration $generation, string $state): void
    {
        if ($generation->consumption_status !== 'reserved') {
            return;
        }
        if ($generation->source !== 'free') {
            app(QuotationCommerce::class)->settle($generation, $state);

            return;
        }
        $quota = DB::table('quotation_daily_quotas')->where('id', $generation->quota_id)->lockForUpdate()->first();
        if (! $quota || (int) $quota->reserved !== 1) {
            throw new QuotationGenerationException('quota_inconsistent', 503);
        }
        DB::table('quotation_daily_quotas')->where('id', $quota->id)->update(['reserved' => 0,
            'consumed' => $quota->consumed + ($state === 'consumed' ? 1 : 0), 'updated_at' => now()]);
        $generation->update(['consumption_status' => $state]);
    }

    private function assertNoActive(int $userId): void
    {
        if (QuotationGeneration::where('user_id', $userId)->where('consumption_status', 'reserved')->lockForUpdate()->first()) {
            throw new QuotationGenerationException('generation_in_progress');
        }
    }

    private function dailyBucket(int $userId, string $date): object
    {
        DB::table('quotation_daily_quotas')->insertOrIgnore(['user_id' => $userId, 'reservation_date' => $date, 'reserved' => 0, 'consumed' => 0, 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('quotation_daily_quotas')->where('user_id', $userId)->where('reservation_date', $date)->first();
    }

    private function reserve(int $userId, string $date): object
    {
        DB::table('quotation_daily_quotas')->insertOrIgnore(['user_id' => $userId, 'reservation_date' => $date, 'reserved' => 0, 'consumed' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $quota = DB::table('quotation_daily_quotas')->where('user_id', $userId)->where('reservation_date', $date)->lockForUpdate()->first();
        if ($quota->reserved + $quota->consumed >= 1) {
            throw new QuotationGenerationException('free_quota_exhausted', 422);
        }
        DB::table('quotation_daily_quotas')->where('id', $quota->id)->update(['reserved' => 1, 'updated_at' => now()]);

        return $quota;
    }

    private function replay(int $userId, string $key, string $hash): ?Processing
    {
        $request = DB::table('quotation_generation_requests')->where('user_id', $userId)->where('idempotency_key', $key)->lockForUpdate()->first();
        if (! $request) {
            return null;
        }
        if (! hash_equals($request->request_hash, $hash)) {
            throw new QuotationGenerationException('idempotency_conflict');
        }

        return Processing::findOrFail(QuotationGeneration::findOrFail($request->generation_id)->processing_id);
    }

    private function remember(int $userId, string $key, string $hash, int $generationId): void
    {
        DB::table('quotation_generation_requests')->insert(['user_id' => $userId, 'idempotency_key' => $key, 'request_hash' => $hash, 'generation_id' => $generationId, 'created_at' => now()]);
    }

    private function available(): void
    {
        if (! config('quotations.enabled') || ! app(QuotationExecutionRunner::class)->available()) {
            throw new QuotationGenerationException('generation_unavailable', 503);
        }
    }

    private function enqueue(string $uuid): void
    {
        $configuration = config('queue.connections.quotations');
        if (($configuration['driver'] ?? null) !== 'database' || ($configuration['after_commit'] ?? true)
            || ($configuration['connection'] ?? config('database.default')) !== config('database.default')
            || ($configuration['table'] ?? '') !== 'jobs') {
            throw new QuotationGenerationException('queue_unavailable', 503);
        }
        try {
            Queue::connection('quotations')->push(new GenerateQuotationPdfJob($uuid), queue: 'quotations');
        } catch (Throwable) {
            throw new QuotationGenerationException('queue_unavailable', 503);
        }
    }
}
