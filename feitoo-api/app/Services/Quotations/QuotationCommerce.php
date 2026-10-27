<?php

namespace App\Services\Quotations;

use App\Exceptions\QuotationGenerationException;
use App\Models\QuotationGeneration;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class QuotationCommerce
{
    // Caller holds User first. Never acquire Processing/Generation after a financial bucket.
    public function pro(User $user): ?object
    {
        $sub = app(SubscriptionService::class)->current($user, true);
        if (! $sub?->grantsPro() || ! $sub->current_period_start || $sub->current_period_start->isFuture()
            || $sub->current_period_start->greaterThanOrEqualTo($sub->current_period_end)) {
            return null;
        }
        DB::table('quotation_pro_buckets')->insertOrIgnore(['user_id' => $user->id, 'subscription_id' => $sub->id,
            'period_start' => $sub->current_period_start, 'period_end' => $sub->current_period_end,
            'quota_limit' => config('quotations.pro_limit'), 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('quotation_pro_buckets')->where('subscription_id', $sub->id)
            ->where('period_start', $sub->current_period_start)->where('period_end', $sub->current_period_end)->lockForUpdate()->first();
    }

    public function summary(User $user): array
    {
        return DB::transaction(function () use ($user) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $pro = $this->pro($user);
            $credits = DB::table('quotation_credit_grants')->where('user_id', $user->id)
                ->selectRaw('COALESCE(SUM(granted),0) granted, COALESCE(SUM(reserved),0) reserved, COALESCE(SUM(consumed),0) consumed, COALESCE(SUM(revoked),0) revoked')->first();

            return ['credits' => [...array_map('intval', (array) $credits), 'available' => (int) $credits->granted - (int) $credits->reserved - (int) $credits->consumed - (int) $credits->revoked],
                'pro' => $pro ? ['active' => true, 'limit' => $pro->quota_limit, 'reserved' => $pro->reserved,
                    'consumed' => $pro->consumed, 'remaining' => $pro->quota_limit - $pro->reserved - $pro->consumed,
                    'period_start' => Carbon::parse($pro->period_start, config('app.timezone'))->toIso8601String(),
                    'period_end' => Carbon::parse($pro->period_end, config('app.timezone'))->toIso8601String()] : ['active' => false, 'remaining' => 0],
                'offer' => ['code' => 'quotation_5', 'credits' => 5, 'amount' => 990, 'currency' => 'brl',
                    'available' => app(QuotationStripeGateway::class)->available()]];
        }, 5);
    }

    public function reserve(User $user, ?string $requested, ?QuotationGeneration $retry = null): array
    {
        $pro = $this->pro($user);
        $source = $requested ?? ($pro ? 'pro' : 'free');
        if ($pro && $pro->reserved + $pro->consumed < $pro->quota_limit && $source !== 'pro') {
            throw new QuotationGenerationException('pro_first', 422);
        }
        if ($source === 'pro') {
            if (! $pro || ($retry && (int) $retry->pro_bucket_id !== (int) $pro->id)) {
                throw new QuotationGenerationException('pro_period_invalid', 422);
            }
            if ($pro->reserved + $pro->consumed >= $pro->quota_limit) {
                throw new QuotationGenerationException('pro_quota_exhausted', 422);
            }
            DB::table('quotation_pro_buckets')->where('id', $pro->id)->increment('reserved');

            return ['source' => 'pro', 'pro_bucket_id' => $pro->id];
        }
        if ($source === 'credit') {
            $query = DB::table('quotation_credit_grants')->where('user_id', $user->id)->whereNull('blocked_at')
                ->whereRaw('granted > reserved + consumed + revoked')->orderBy('id');
            if ($retry) {
                $query->where('id', $retry->credit_grant_id);
            }
            $grant = $query->lockForUpdate()->first();
            if (! $grant) {
                throw new QuotationGenerationException('credits_exhausted', 422);
            }
            DB::table('quotation_credit_grants')->where('id', $grant->id)->increment('reserved');

            return ['source' => 'credit', 'credit_grant_id' => $grant->id];
        }
        if ($source !== 'free' || $pro) {
            throw new QuotationGenerationException('source_invalid', 422);
        }

        return ['source' => 'free'];
    }

    public function blocked(QuotationGeneration $generation): bool
    {
        return $generation->source === 'credit' && DB::table('quotation_credit_grants')->where('id', $generation->credit_grant_id)->whereNotNull('blocked_at')->exists();
    }

    public function settle(QuotationGeneration $generation, string $state): void
    {
        $credit = $generation->source === 'credit';
        $table = $credit ? 'quotation_credit_grants' : 'quotation_pro_buckets';
        $id = $credit ? $generation->credit_grant_id : $generation->pro_bucket_id;
        $bucket = DB::table($table)->where('id', $id)->lockForUpdate()->first();
        if (! $bucket || $bucket->reserved < 1) {
            throw new QuotationGenerationException('quota_inconsistent', 503);
        }
        $values = ['reserved' => $bucket->reserved - 1, 'consumed' => $bucket->consumed + ($state === 'consumed' ? 1 : 0), 'updated_at' => now()];
        if ($credit && $bucket->blocked_at && $state === 'released') {
            $values['revoked'] = $bucket->revoked + 1;
        }
        DB::table($table)->where('id', $id)->update($values);
        $generation->update(['consumption_status' => $state]);
    }
}
