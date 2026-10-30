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
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;

class QuotationOrderService
{
    public function __construct(private readonly QuotationStripeGateway $stripe) {}

    public function checkout(User $owner, string $key, string $destination = 'editor'): QuotationCreditOrder
    {
        QuotationOrderDiagnostics::stage('checkout_configuration');
        if (! in_array($destination, ['editor', 'account'], true)) {
            throw new QuotationGenerationException('invalid_request', 422);
        }
        if (! config('quotations.enabled') || ! config('quotations.purchases_enabled') || ! $this->stripe->available()) {
            throw new QuotationGenerationException('checkout_unavailable', 503);
        }
        // Persist before remote I/O: ambiguous failures reuse exactly the same provider key and payload.
        QuotationOrderDiagnostics::stage('order_persist');
        $order = DB::transaction(function () use ($owner, $key, $destination) {
            User::whereKey($owner->id)->lockForUpdate()->firstOrFail();

            return QuotationCreditOrder::firstOrCreate(['user_id' => $owner->id, 'idempotency_key' => $key],
                ['uuid' => (string) Str::uuid(), 'stripe_mode' => config('monetization.stripe.mode'), 'package_code' => 'quotation_5', 'amount' => 990, 'currency' => 'brl',
                    'credits' => 5, 'price_id' => config('quotations.credit_price_id'), 'return_destination' => $destination, 'expires_at' => now()->addHour()]);
        }, 5);

        QuotationOrderDiagnostics::stage('order_reload', $order);
        $order->refresh(); // Load database defaults on newly created Eloquent instances too.
        $this->assertMode($order);
        if ($order->status !== 'pending') {
            return $order;
        }
        if ($order->checkout_url) {
            return $order->expires_at->isPast() ? $this->refresh($order) : $order;
        }
        // A Stripe retry retains its original expiry. Never recreate an old ambiguous charge.
        if ($order->expires_at->lessThan(now()->addMinutes(30))) {
            $order = $this->refresh($order);
            if ($order->session_id || $order->status !== 'pending') {
                return $order;
            }
            throw new QuotationGenerationException('checkout_expired', 409);
        }
        QuotationOrderDiagnostics::stage('stripe_checkout', $order);
        try {
            $session = $this->stripe->checkout($order);
        } catch (ApiErrorException $error) {
            if (! $error instanceof ApiConnectionException && $error->getHttpStatus() < 500) {
                throw $error;
            }
            QuotationOrderDiagnostics::report($error);
            // A timeout or provider 5xx can hide a successful creation. Reuse bounded GET recovery immediately.
            $recovered = $this->recoverSession($order);
            if ($recovered->session_id || $recovered->status !== 'pending') {
                return $recovered;
            }
            // An empty search during the original lifetime is not proof of remote absence.
            throw $error;
        }
        QuotationOrderDiagnostics::stage('session_validate', $order);
        $this->validate($order, $session, false);
        app(StripeEnvironment::class)->assertSession($session, true);

        QuotationOrderDiagnostics::stage('session_persist', $order);

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
        $this->assertMode($order);
        app(StripeEnvironment::class)->assertSession($session);
        if (($registered && $order->session_id !== $session->id)
            || $session->mode !== 'payment' || $session->client_reference_id !== $order->uuid
            || ($session->metadata->quotation_order ?? null) !== $order->uuid
            || ($session->metadata->operation ?? null) !== 'quotation_credits'
            || $session->amount_total !== $order->amount || $session->currency !== $order->currency) {
            throw new QuotationGenerationException('payment_mismatch', 422);
        }
    }

    public function refresh(QuotationCreditOrder $order): QuotationCreditOrder
    {
        QuotationOrderDiagnostics::stage('order_refresh', $order);
        $this->assertMode($order);
        if ($order->status === 'pending' && ! $order->session_id && $order->expires_at->lessThan(now()->addMinutes(30))) {
            return $this->recoverSession($order);
        }
        if ($order->status === 'pending' && $order->session_id) {
            QuotationOrderDiagnostics::stage('stripe_session_retrieve', $order);
            $session = $this->stripe->session($order->session_id);
            QuotationOrderDiagnostics::stage('session_reconcile', $order);
            DB::transaction(function () use ($order, $session) {
                User::whereKey($order->user_id)->lockForUpdate()->firstOrFail();
                $locked = QuotationCreditOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
                $this->applySession($locked, $session);
            }, 5);
        }

        return $order->fresh();
    }

    private function recoverSession(QuotationCreditOrder $order): QuotationCreditOrder
    {
        QuotationOrderDiagnostics::stage('stripe_session_recovery', $order);
        $session = $this->stripe->findSession($order);
        QuotationOrderDiagnostics::stage('session_recovery_persist', $order);
        DB::transaction(function () use ($order, $session) {
            User::whereKey($order->user_id)->lockForUpdate()->firstOrFail();
            $locked = QuotationCreditOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending' || $locked->session_id) {
                return;
            }
            if ($session) {
                $this->validate($locked, $session, false);
                app(StripeEnvironment::class)->assertSession($session, $session->status === 'open');
                $locked->update(['session_id' => $session->id, 'checkout_url' => $session->status === 'open' ? $session->url : null]);
                $this->applySession($locked, $session);
            } elseif ($locked->expires_at->isPast()) {
                // The original session lifetime ended and an exhaustive bounded GET found no session.
                $locked->update(['status' => 'cancelled', 'checkout_url' => null]);
            }
        }, 5);

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
        } elseif ($session->status !== 'open') {
            $order->update(['checkout_url' => null]);
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
        $this->assertMode($candidate);
        $supported = ['checkout.session.completed', 'checkout.session.async_payment_succeeded', 'checkout.session.async_payment_failed',
            'checkout.session.expired', 'payment_intent.payment_failed', 'charge.refunded', 'charge.dispute.created', 'charge.dispute.closed'];
        if (! in_array($event->type, $supported, true)) {
            return true;
        }
        if (DB::table('stripe_webhook_events')->where('provider_event_id', $event->id)->exists()) {
            return true;
        }
        if (str_starts_with($event->type, 'charge.dispute.')) {
            // Retrieve outside financial locks: event delivery order is not a state ordering guarantee.
            $current = $this->stripe->dispute($object->id);
            if ($current->id !== $object->id || $current->payment_intent !== $intent) {
                throw new QuotationGenerationException('payment_mismatch', 422);
            }
            $object = $current;
        }
        DB::transaction(function () use ($candidate, $event, $object, $intent) {
            User::whereKey($candidate->user_id)->lockForUpdate()->firstOrFail();
            $order = QuotationCreditOrder::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            if (! DB::table('stripe_webhook_events')->insertOrIgnore(['provider_event_id' => $event->id, 'type' => $event->type, 'processed_at' => now()])) {
                return;
            }
            if (str_starts_with($event->type, 'checkout.session.')) {
                if (! $order->session_id) {
                    // A signed event can arrive after Stripe created the Session but before our response was stored.
                    $this->validate($order, $object, false);
                    $order->update(['session_id' => $object->id]);
                }
                $this->applySession($order, $object);
                if ($event->type === 'checkout.session.async_payment_failed' && $order->status === 'pending') {
                    $order->update(['status' => 'failed', 'checkout_url' => null]);
                }
            } elseif ($event->type === 'payment_intent.payment_failed') {
                if ($object->amount !== $order->amount || $object->currency !== $order->currency
                    || ($object->metadata->operation ?? null) !== 'quotation_credits'
                    || ($object->metadata->quotation_order ?? null) !== $order->uuid
                    || ($order->payment_intent_id && $order->payment_intent_id !== $object->id)) {
                    throw new QuotationGenerationException('payment_mismatch', 422);
                }
                if ($order->status === 'pending') {
                    $order->update(['status' => 'failed', 'payment_intent_id' => $object->id, 'checkout_url' => null]);
                }
            } else {
                if (! is_string($intent) || ($order->payment_intent_id && $order->payment_intent_id !== $intent)
                    || $object->currency !== $order->currency || ! is_int($object->amount) || $object->amount < 1 || $object->amount > $order->amount
                    || ($event->type === 'charge.refunded' && ($object->amount !== $order->amount || ! is_int($object->amount_refunded)
                        || $object->amount_refunded < 0 || $object->amount_refunded > $order->amount))) {
                    throw new QuotationGenerationException('payment_mismatch', 422);
                }
                if ($event->type === 'charge.refunded' && $object->amount_refunded < 1) {
                    return;
                }
                $grant = DB::table('quotation_credit_grants')->where('order_id', $order->id)->lockForUpdate()->first();
                if (str_starts_with($event->type, 'charge.dispute.') && $object->status === 'won'
                    && $order->status !== 'refunded' && $order->refunded_amount === 0) {
                    // Restore only unspent units; the original grant and immutable consumption survive.
                    if ($grant) {
                        DB::table('quotation_credit_grants')->where('id', $grant->id)->update(['blocked_at' => null, 'revoked' => 0, 'updated_at' => now()]);
                    }
                    $order->update(['status' => $order->paid_at ? 'paid' : 'pending', 'payment_intent_id' => $intent, 'checkout_url' => null]);

                    return;
                }
                // Any partial refund or dispute blocks the remaining package; consumed records remain immutable.
                $order->update(['status' => $event->type === 'charge.refunded' ? 'refunded' : ($order->status === 'refunded' ? 'refunded' : 'disputed'),
                    'payment_intent_id' => $intent, 'refunded_amount' => max($order->refunded_amount, (int) ($object->amount_refunded ?? 0)), 'checkout_url' => null]);
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
        return ['id' => $order->uuid, 'stripe_mode' => $order->stripe_mode, 'package_code' => $order->package_code, 'amount' => $order->amount, 'currency' => $order->currency,
            'credits' => $order->credits, 'status' => $order->status,
            'checkout_url' => $order->status === 'pending' && $order->expires_at->isFuture() ? $order->checkout_url : null,
            'expires_at' => $order->expires_at->toIso8601String(),
            'actions' => ['refresh' => $order->status === 'pending', 'resume_checkout' => $order->status === 'pending' && $order->expires_at->isFuture() && (bool) $order->checkout_url],
            'created_at' => $order->created_at->toIso8601String(), 'paid_at' => $order->paid_at?->toIso8601String(), 'refunded_amount' => $order->refunded_amount];
    }

    private function assertMode(QuotationCreditOrder $order): void
    {
        if (! in_array($order->stripe_mode, ['test', 'live'], true) || $order->stripe_mode !== config('monetization.stripe.mode')) {
            throw new QuotationGenerationException('payment_mismatch', 422);
        }
    }
}
