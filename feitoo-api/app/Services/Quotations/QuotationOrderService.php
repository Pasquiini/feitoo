<?php

namespace App\Services\Quotations;

use App\Exceptions\QuotationGenerationException;
use App\Models\Purchase;
use App\Models\QuotationCreditOrder;
use App\Models\User;
use App\Services\StripeEnvironment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Stripe\Event;

class QuotationOrderService
{
    public function __construct(private readonly QuotationStripeGateway $stripe) {}

    public function checkout(User $owner, string $key): QuotationCreditOrder
    {
        if (! config('quotations.enabled') || ! $this->stripe->available()) {
            throw new QuotationGenerationException('checkout_unavailable', 503);
        }
        // Persist before remote I/O: ambiguous failures reuse exactly the same provider key and payload.
        $order = DB::transaction(function () use ($owner, $key) {
            User::whereKey($owner->id)->lockForUpdate()->firstOrFail();

            return QuotationCreditOrder::firstOrCreate(['user_id' => $owner->id, 'idempotency_key' => $key],
                ['uuid' => (string) Str::uuid(), 'package_code' => 'quotation_5', 'amount' => 990, 'currency' => 'brl',
                    'credits' => 5, 'price_id' => config('quotations.credit_price_id'), 'expires_at' => now()->addHour()]);
        }, 5);

        $order->refresh(); // Load database defaults on newly created Eloquent instances too.
        if ($order->status !== 'pending') {
            return $order;
        }
        if ($order->checkout_url) {
            return $order->expires_at->isPast() ? $this->refresh($order) : $order;
        }
        $session = $this->stripe->checkout($order);
        $this->validate($order, $session, false);
        app(StripeEnvironment::class)->assertSession($session, true);

        return DB::transaction(function () use ($owner, $order, $session) {
            User::whereKey($owner->id)->lockForUpdate()->firstOrFail();
            $order = QuotationCreditOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->session_id && $order->session_id !== $session->id) {
                throw new QuotationGenerationException('payment_mismatch', 422);
            }
            $order->update(['session_id' => $session->id, 'checkout_url' => $order->status === 'pending' ? $session->url : null]);

            return $order;
        }, 5);
    }

    private function validate(QuotationCreditOrder $order, object $session, bool $registered = true): void
    {
        app(StripeEnvironment::class)->assertSession($session);
        if (config('monetization.stripe.mode') !== 'test' || ($registered && $order->session_id !== $session->id)
            || $session->mode !== 'payment' || $session->client_reference_id !== $order->uuid
            || ($session->metadata->quotation_order ?? null) !== $order->uuid
            || ($session->metadata->operation ?? null) !== 'quotation_credits'
            || $session->amount_total !== $order->amount || $session->currency !== $order->currency) {
            throw new QuotationGenerationException('payment_mismatch', 422);
        }
    }

    public function refresh(QuotationCreditOrder $order): QuotationCreditOrder
    {
        if ($order->status === 'pending' && $order->session_id) {
            $session = $this->stripe->session($order->session_id);
            DB::transaction(function () use ($order, $session) {
                User::whereKey($order->user_id)->lockForUpdate()->firstOrFail();
                $locked = QuotationCreditOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
                $this->applySession($locked, $session);
            }, 5);
        }

        return $order->fresh();
    }

    private function applySession(QuotationCreditOrder $order, object $session): void
    {
        $this->validate($order, $session);
        if (in_array($order->status, ['refunded', 'disputed'], true)) {
            return;
        }
        if ($session->payment_status === 'paid' && is_string($session->payment_intent)) {
            if ($order->payment_intent_id && $order->payment_intent_id !== $session->payment_intent) {
                throw new QuotationGenerationException('payment_mismatch', 422);
            }
            $order->update(['status' => 'paid', 'paid_at' => $order->paid_at ?? now(),
                'payment_intent_id' => $session->payment_intent, 'checkout_url' => null]);
            DB::table('quotation_credit_grants')->insertOrIgnore(['user_id' => $order->user_id, 'order_id' => $order->id,
                'granted' => $order->credits, 'created_at' => now(), 'updated_at' => now()]);
        } elseif ($session->status === 'expired' && $order->status !== 'paid') {
            $order->update(['status' => 'cancelled', 'checkout_url' => null]);
        }
    }

    public function handle(Event $event): bool
    {
        $object = $event->data->object;
        $uuid = $object->metadata->quotation_order ?? null;
        $intent = $object->payment_intent ?? null;
        $candidate = is_string($uuid) ? QuotationCreditOrder::where('uuid', $uuid)->first() : null;
        if (! $candidate && is_string($intent)) {
            $candidate = QuotationCreditOrder::where('payment_intent_id', $intent)->first();
        }
        // Refund/dispute may precede checkout confirmation; PaymentIntent carries our technical routing metadata.
        if (! $candidate && is_string($intent) && (str_starts_with($event->type, 'charge.') || str_starts_with($event->type, 'refund.'))
            && config('monetization.stripe.mode') === 'test'
            && QuotationCreditOrder::whereNull('payment_intent_id')->exists()
            && ! isset($object->metadata->purchase_uuid)
            && ! Purchase::where('provider_payment_intent_id', $intent)->exists()) {
            $uuid = $this->stripe->orderForIntent($intent);
            if ($uuid) {
                $candidate = QuotationCreditOrder::where('uuid', $uuid)->first();
            }
        }
        if (! $candidate) {
            if (is_string($uuid)) {
                throw new QuotationGenerationException('order_not_ready', 503);
            }

            return false;
        }
        if (config('monetization.stripe.mode') !== 'test') {
            throw new QuotationGenerationException('checkout_unavailable', 503);
        }
        $supported = ['checkout.session.completed', 'checkout.session.async_payment_succeeded', 'checkout.session.async_payment_failed',
            'checkout.session.expired', 'payment_intent.payment_failed', 'charge.refunded', 'charge.dispute.created', 'charge.dispute.closed'];
        if (! in_array($event->type, $supported, true)) {
            return true;
        }
        DB::transaction(function () use ($candidate, $event, $object, $intent) {
            User::whereKey($candidate->user_id)->lockForUpdate()->firstOrFail();
            $order = QuotationCreditOrder::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            if (! DB::table('stripe_webhook_events')->insertOrIgnore(['provider_event_id' => $event->id, 'type' => $event->type, 'processed_at' => now()])) {
                return;
            }
            if (str_starts_with($event->type, 'checkout.session.')) {
                if (! $order->session_id) {
                    throw new QuotationGenerationException('order_not_ready', 503);
                }
                $this->applySession($order, $object);
                if ($event->type === 'checkout.session.async_payment_failed' && $order->status === 'pending') {
                    $order->update(['status' => 'failed', 'checkout_url' => null]);
                }
            } elseif ($event->type === 'payment_intent.payment_failed') {
                if ($object->amount !== $order->amount || $object->currency !== $order->currency) {
                    throw new QuotationGenerationException('payment_mismatch', 422);
                }
                if ($order->status === 'pending') {
                    $order->update(['status' => 'failed', 'payment_intent_id' => $object->id, 'checkout_url' => null]);
                }
            } else {
                if (! is_string($intent) || ($order->payment_intent_id && $order->payment_intent_id !== $intent)
                    || $object->currency !== $order->currency || ($event->type === 'charge.refunded' && $object->amount !== $order->amount)) {
                    throw new QuotationGenerationException('payment_mismatch', 422);
                }
                if ($event->type === 'charge.refunded' && $object->amount_refunded < 1) {
                    return;
                }
                // Any partial refund or dispute blocks the remaining package; consumed records remain immutable.
                $order->update(['status' => $event->type === 'charge.refunded' ? 'refunded' : ($order->status === 'refunded' ? 'refunded' : 'disputed'),
                    'payment_intent_id' => $intent, 'refunded_amount' => max($order->refunded_amount, (int) ($object->amount_refunded ?? 0)), 'checkout_url' => null]);
                $grant = DB::table('quotation_credit_grants')->where('order_id', $order->id)->lockForUpdate()->first();
                if ($grant) {
                    DB::table('quotation_credit_grants')->where('id', $grant->id)->update(['blocked_at' => $grant->blocked_at ?? now(),
                        'revoked' => $grant->granted - $grant->reserved - $grant->consumed, 'updated_at' => now()]);
                }
            }
        }, 5);

        return true;
    }

    public function resource(QuotationCreditOrder $order): array
    {
        return ['id' => $order->uuid, 'package_code' => $order->package_code, 'amount' => $order->amount, 'currency' => $order->currency,
            'credits' => $order->credits, 'status' => $order->status, 'checkout_url' => $order->checkout_url,
            'created_at' => $order->created_at->toIso8601String(), 'paid_at' => $order->paid_at?->toIso8601String(), 'refunded_amount' => $order->refunded_amount];
    }
}
