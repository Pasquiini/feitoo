<?php

namespace App\Services;

use App\Enums\PurchaseStatus;
use App\Models\Processing;
use App\Models\Purchase;
use App\Services\Quotations\QuotationOrderService;
use Illuminate\Support\Facades\DB;
use Stripe\Event;

class StripeWebhookService
{
    public function handle(Event $event): void
    {
        app(StripeEnvironment::class)->assertObject($event, 400);
        app(StripeEnvironment::class)->assertObject($event->data->object, 400);
        if (app(QuotationOrderService::class)->handle($event)) {
            return;
        }
        if (app(SubscriptionService::class)->handle($event)) {
            return;
        }
        if (! in_array($event->type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded',
            'checkout.session.async_payment_failed', 'checkout.session.expired', 'payment_intent.payment_failed', 'charge.refunded'], true)) {
            return;
        }
        DB::transaction(function () use ($event): void {
            if (! DB::table('stripe_webhook_events')->insertOrIgnore([
                'provider_event_id' => $event->id, 'type' => $event->type, 'processed_at' => now(),
            ])) {
                return;
            }
            $object = $event->data->object;
            $uuid = $object->metadata->purchase_uuid ?? null;
            $query = Purchase::query();
            if (is_string($uuid)) {
                $query->where('uuid', $uuid);
            } elseif ($event->type === 'charge.refunded' && is_string($object->payment_intent)) {
                $query->where('provider_payment_intent_id', $object->payment_intent);
            } else {
                return;
            }
            $candidate = $query->first();
            if (! $candidate) {
                return;
            }
            // Same lock order as start/cancel/purge; no parser or file I/O in the webhook.
            $processing = Processing::query()->whereKey($candidate->processing_id)->lockForUpdate()->first();
            $purchase = Purchase::query()->whereKey($candidate->id)->lockForUpdate()->first();
            if (! $purchase || ! $processing) {
                return;
            }
            if ($purchase->provider_checkout_session_id
                && ! str_starts_with($purchase->provider_checkout_session_id, 'cs_'.config('monetization.stripe.mode').'_')) {
                throw new \RuntimeException('Purchase belongs to another Stripe environment.');
            }
            if (str_starts_with($event->type, 'checkout.session.')) {
                app(PurchaseService::class)->applySession($purchase, $processing, $object, $event->type);
            } elseif ($event->type === 'payment_intent.payment_failed') {
                if ($object->amount !== $purchase->amount || $object->currency !== $purchase->currency
                    || ($object->metadata->processing_uuid ?? null) !== $processing->uuid
                    || ($object->metadata->package_code ?? null) !== $purchase->package_code
                    || ($purchase->provider_payment_intent_id && $purchase->provider_payment_intent_id !== $object->id)
                    || ! in_array($purchase->status, [PurchaseStatus::Pending, PurchaseStatus::Failed], true)) {
                    return;
                }
                $purchase->update(['status' => PurchaseStatus::Failed, 'provider_payment_intent_id' => $object->id]);
            } elseif ($object->currency === $purchase->currency && $object->amount === $purchase->amount
                && $object->amount_refunded >= $purchase->amount && is_string($object->payment_intent)
                && (! $purchase->provider_payment_intent_id || $purchase->provider_payment_intent_id === $object->payment_intent)) {
                $purchase->update(['status' => PurchaseStatus::Refunded, 'provider_payment_intent_id' => $object->payment_intent, 'checkout_url' => null]);
            }
        }, 5);
    }
}
