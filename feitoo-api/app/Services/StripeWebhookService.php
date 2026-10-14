<?php

namespace App\Services;

use App\Enums\PurchaseStatus;
use App\Models\Processing;
use App\Models\Purchase;
use Illuminate\Support\Facades\DB;
use Stripe\Event;

class StripeWebhookService
{
    public function handle(Event $event): void
    {
        if (app(SubscriptionService::class)->handle($event)) {
            return;
        }
        if (! in_array($event->type, ['checkout.session.completed', 'checkout.session.expired', 'payment_intent.payment_failed', 'charge.refunded'], true)) {
            return;
        }
        DB::transaction(function () use ($event): void {
            if (! DB::table('stripe_webhook_events')->insertOrIgnore([
                'provider_event_id' => $event->id, 'type' => $event->type, 'processed_at' => now(),
            ])) {
                return;
            }
            $object = $event->data->object;
            if ($object->livemode !== false) {
                return;
            }
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
            if (str_starts_with($event->type, 'checkout.session.')) {
                if (! $purchase->provider_checkout_session_id) {
                    // Do not permanently deduplicate an event that preceded Session persistence.
                    throw new \RuntimeException('Checkout registration is not ready.');
                }
                if ($object->id !== $purchase->provider_checkout_session_id
                    || ($object->metadata->processing_uuid ?? null) !== $processing->uuid
                    || ($object->metadata->package_code ?? null) !== $purchase->package_code) {
                    return;
                }
                if ($event->type === 'checkout.session.completed') {
                    if ($object->mode !== 'payment' || $object->payment_status !== 'paid'
                        || $object->amount_total !== $purchase->amount || $object->currency !== $purchase->currency
                        || ! is_string($object->payment_intent) || $purchase->status === PurchaseStatus::Refunded) {
                        return;
                    }
                    $purchase->update(['status' => PurchaseStatus::Paid, 'paid_at' => $purchase->paid_at ?? now(),
                        'provider_payment_intent_id' => $object->payment_intent, 'checkout_url' => null]);
                } elseif (! in_array($purchase->status, [PurchaseStatus::Paid, PurchaseStatus::Refunded], true)) {
                    $purchase->update(['status' => PurchaseStatus::Expired, 'checkout_url' => null, 'checkout_closed_at' => now()]);
                }
            } elseif ($event->type === 'payment_intent.payment_failed') {
                if ($object->amount !== $purchase->amount || $object->currency !== $purchase->currency
                    || ($object->metadata->processing_uuid ?? null) !== $processing->uuid
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
