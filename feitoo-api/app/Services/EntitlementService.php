<?php

namespace App\Services;

use App\Enums\ProcessingTool;
use App\Enums\PurchaseStatus;
use App\Exceptions\BillingException;
use App\Models\Processing;
use App\Models\Purchase;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class EntitlementService
{
    public function freeLimits(ProcessingTool $tool): array
    {
        abort_if($tool === ProcessingTool::QuotationGenerator, 422);
        $key = in_array($tool, [ProcessingTool::XmlToExcel, ProcessingTool::ProductsFromXml], true)
            ? 'monetization.xml_demo' : ($tool === ProcessingTool::DocxToPdf ? 'documents.limits.free' : 'monetization.free');

        return ['per_processing' => (int) config($key.'.per_processing'), 'daily' => (int) config($key.'.daily')];
    }

    public function usage(User $user, ?Carbon $local = null, bool $locked = false, ProcessingTool $tool = ProcessingTool::XmlToExcel): array
    {
        abort_if($tool === ProcessingTool::QuotationGenerator, 422);
        $local ??= Carbon::now(config('monetization.free.timezone'));
        $plan = app(SubscriptionService::class)->current($user, $locked)?->grantsPro() ? 'pro' : 'free';
        $query = $user->processings()->whereIn('entitlement_source', ['free', 'pro'])
            ->where('usage_date', $local->toDateString())->where('tool', $tool->value);
        // A locking read also avoids an earlier repeatable-read snapshot while waiting for the User lock.
        $used = (int) ($locked ? $query->lockForUpdate()->get(['usage_files_count'])->sum('usage_files_count') : $query->sum('usage_files_count'));
        $source = $tool === ProcessingTool::DocxToPdf ? 'documents.limits.' : 'monetization.';
        $configured = $plan === 'free' ? $this->freeLimits($tool) : config($source.'pro');
        $daily = max(0, (int) $configured['daily']);

        return ['plan' => $plan, 'limits' => [
            'per_processing' => max(0, min((int) $configured['per_processing'], (int) config($tool === ProcessingTool::DocxToPdf ? 'documents.max_files' : 'feitoo.uploads.max_processing_files'))),
            'daily' => $daily, 'used_today' => $used, 'remaining_today' => max(0, $daily - $used),
            'resets_at' => $local->copy()->addDay()->startOfDay()->toIso8601String(),
        ]];
    }

    // Caller holds the Processing lock. The User lock serializes starts across different batches.
    public function accept(Processing $processing, User $owner): void
    {
        abort_if($processing->tool === ProcessingTool::QuotationGenerator, 409);
        $user = User::query()->lockForUpdate()->findOrFail($owner->id);
        $purchase = Purchase::query()->where('processing_id', $processing->id)->lockForUpdate()->first();
        if ($purchase?->status === PurchaseStatus::Paid) {
            if ($purchase->consumed_at || $purchase->files_count !== $processing->files_count || $processing->files_count > $purchase->files_limit) {
                throw new BillingException('purchase_unavailable', 'A compra não está disponível para este lote.');
            }
            $purchase->update(['consumed_at' => now()]);
            $processing->update(['entitlement_source' => 'purchase', 'usage_date' => null, 'usage_files_count' => 0]);

            return;
        }
        if ($purchase && (in_array($purchase->status, [PurchaseStatus::Pending, PurchaseStatus::Failed], true)
            || ($purchase->status === PurchaseStatus::Expired && ! $purchase->checkout_closed_at))) {
            throw new BillingException('payment_pending', 'O pagamento ainda não foi confirmado. Cancele o Checkout para usar o Free.');
        }
        $local = Carbon::now(config('monetization.free.timezone'));
        $usage = $this->usage($user, $local, true, $processing->tool);
        $limits = $usage['limits'];
        if ($processing->files_count > $limits['per_processing']) {
            throw new BillingException('purchase_required', 'O lote ultrapassa o limite do seu plano por processamento.');
        }
        if ($processing->files_count > $limits['remaining_today']) {
            throw new BillingException('usage_limit_exceeded', 'O lote ultrapassa o saldo do seu plano hoje.');
        }
        $processing->update(['entitlement_source' => $usage['plan'],
            'usage_date' => $local->toDateString(),
            'usage_files_count' => $processing->files_count]);
    }

    public function retentionMinutes(User $user): int
    {
        return max(1, (int) ($this->usage($user)['plan'] === 'pro'
            ? config('monetization.pro.ttl_minutes') : config('feitoo.processing.ttl_minutes')));
    }

    public function releaseDispatchFailure(Processing $processing): void
    {
        DB::transaction(function () use ($processing): void {
            $locked = Processing::query()->lockForUpdate()->findOrFail($processing->id);
            User::query()->whereKey($locked->user_id)->lockForUpdate()->first();
            $locked->update(['usage_files_count' => 0, 'usage_date' => null, 'entitlement_source' => null]);
            Purchase::query()->where('processing_id', $locked->id)->where('status', PurchaseStatus::Paid->value)->update(['consumed_at' => null]);
        }, 5);
    }
}
