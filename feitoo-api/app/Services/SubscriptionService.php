<?php

namespace App\Services;

use App\Enums\SubscriptionStatus;
use App\Exceptions\BillingException;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Stripe\Event;

class SubscriptionService
{
    public function __construct(private readonly StripeSubscriptionGateway $stripe) {}

    public function current(User $user, bool $locked = false): ?Subscription
    {
        $query = Subscription::where('user_id', $user->id)->orderByDesc('id');

        return ($locked ? $query->lockForUpdate() : $query)->first();
    }

    public function summary(User $user): array
    {
        $subscription = $this->current($user);
        $terminal = ! $subscription || in_array($subscription->status, [SubscriptionStatus::Canceled, SubscriptionStatus::IncompleteExpired], true);
        $localAttempt = $subscription && $subscription->status === SubscriptionStatus::Incomplete && ! $subscription->provider_subscription_id;
        // Old ambiguous attempts must be reconciled, never replayed beyond Stripe's key retention.
        $recoverable = $localAttempt && ($subscription->checkout_session_id || $subscription->created_at->greaterThan(now()->subHours(23)));
        $portal = (bool) ($user->stripe_customer_id && $subscription && $this->stripe->portalAvailable());
        $state = match (true) {
            ! $subscription => 'free',
            $subscription->grantsPro() && $subscription->cancel_at_period_end => 'cancel_scheduled',
            $subscription->grantsPro() => 'active',
            $localAttempt => $recoverable ? 'checkout_pending' : 'reconciliation_required',
            $subscription->status === SubscriptionStatus::Incomplete => 'payment_pending',
            in_array($subscription->status, [SubscriptionStatus::PastDue, SubscriptionStatus::Unpaid], true) => 'payment_due',
            $terminal => 'ended',
            $subscription->status === SubscriptionStatus::Active => 'period_ended',
            default => $subscription->status->value,
        };

        return ['state' => $state, 'actions' => ['checkout' => $terminal && $this->stripe->available(),
            'resume_checkout' => (bool) ($recoverable && $this->stripe->available()), 'portal' => $portal, 'refresh' => true],
            'offer' => ['amount' => (int) config('monetization.pro.amount'), 'currency' => 'brl',
                'per_processing' => min((int) config('monetization.pro.per_processing'), (int) config('feitoo.uploads.max_processing_files')),
                'daily' => (int) config('monetization.pro.daily'), 'available' => $this->stripe->available()],
            'subscription' => $subscription ? ['status' => $subscription->status->value,
                'amount' => $subscription->contract_amount, 'currency' => $subscription->contract_currency,
                'current_period_end' => $subscription->current_period_end?->toIso8601String(),
                'cancel_at_period_end' => $subscription->cancel_at_period_end,
                'grants_pro' => $subscription->grantsPro()] : null];
    }

    public function checkout(User $owner): string
    {
        app(StripeEnvironment::class)->assertConfigured();
        if (! $this->stripe->available()) {
            throw new BillingException('checkout_unavailable', 'Novas assinaturas estão temporariamente indisponíveis.', 503);
        }

        $subscription = DB::transaction(function () use ($owner) {
            $user = User::whereKey($owner->id)->lockForUpdate()->firstOrFail();
            if (! $user->stripe_customer_id) {
                $user->forceFill(['stripe_customer_id' => $this->stripe->customer($user)])->save();
            }
            $subscription = $this->current($user);
            if (($subscription?->provider_subscription_id && $subscription->status === SubscriptionStatus::Incomplete)
                || ($subscription && ! in_array($subscription->status, [SubscriptionStatus::Canceled, SubscriptionStatus::IncompleteExpired, SubscriptionStatus::Incomplete], true))) {
                throw new BillingException('subscription_exists', 'Gerencie o pagamento da assinatura existente.', 409);
            }
            if ($subscription?->checkout_url && $subscription->checkout_expires_at?->isFuture()) {
                return $subscription;
            }
            if ($subscription?->checkout_session_id && ! $subscription->provider_subscription_id) {
                $session = $this->stripe->session($subscription->checkout_session_id);
                if ($session->status !== 'expired') {
                    throw new BillingException('subscription_confirmation_pending', 'Aguardando confirmação da assinatura existente.', 409);
                }
            }
            if ($subscription && ! $subscription->checkout_session_id && ! $subscription->provider_subscription_id
                && $subscription->created_at->lessThanOrEqualTo(now()->subHours(23))) {
                throw new BillingException('subscription_reconciliation_required', 'Consulte sua assinatura antes de tentar novamente.', 409);
            }
            if (! $subscription || $subscription->provider_subscription_id || $subscription->checkout_session_id) {
                $subscription = Subscription::create(['user_id' => $user->id, 'provider_customer_id' => $user->stripe_customer_id,
                    'provider_price_id' => config('monetization.pro.price_id'),
                    'contract_amount' => (int) config('monetization.pro.amount'), 'contract_currency' => 'brl']);
            }

            return $subscription;
        });

        // Commit the attempt first, so ambiguous network failures retry the same Stripe idempotency key.
        return DB::transaction(function () use ($subscription, $owner) {
            User::whereKey($owner->id)->lockForUpdate()->firstOrFail();
            $subscription = Subscription::whereKey($subscription->id)->lockForUpdate()->firstOrFail();
            if ($subscription->provider_subscription_id) {
                throw new BillingException('subscription_exists', 'Gerencie sua assinatura existente.', 409);
            }
            if ($subscription->checkout_url && $subscription->checkout_expires_at?->isFuture()) {
                $remote = $this->stripe->session($subscription->checkout_session_id);
                app(StripeEnvironment::class)->assertSession($remote, true);
                if ($remote->status !== 'open') {
                    throw new BillingException('subscription_confirmation_pending', 'Consulte a confirmação da assinatura.', 409);
                }

                return $subscription->checkout_url;
            }
            $session = $this->stripe->checkout($subscription);
            app(StripeEnvironment::class)->assertSession($session, true);
            $subscription->update(['checkout_session_id' => $session->id, 'checkout_url' => $session->url,
                'checkout_expires_at' => Carbon::createFromTimestamp($session->expires_at)]);

            return $session->url;
        });
    }

    public function portal(User $user): string
    {
        if (! $user->stripe_customer_id || ! $this->current($user)) {
            throw new BillingException('portal_unavailable', 'Não há assinatura para gerenciar.', 409);
        }

        return $this->stripe->portal($user->stripe_customer_id);
    }

    public function handle(Event $event): bool
    {
        app(StripeEnvironment::class)->assertObject($event, 400);
        app(StripeEnvironment::class)->assertObject($event->data->object, 400);
        $object = $event->data->object;
        $subscriptionEvent = str_starts_with($event->type, 'customer.subscription.');
        $invoiceEvent = in_array($event->type, ['invoice.paid', 'invoice.payment_failed'], true);
        $checkoutEvent = $event->type === 'checkout.session.completed' && ($object->mode ?? null) === 'subscription';
        if (! $subscriptionEvent && ! $invoiceEvent && ! $checkoutEvent) {
            return false;
        }
        if (! in_array($event->type, ['customer.subscription.created', 'customer.subscription.updated',
            'customer.subscription.deleted', 'invoice.paid', 'invoice.payment_failed', 'checkout.session.completed'], true)) {
            return true;
        }
        $id = $subscriptionEvent ? $object->id : ($checkoutEvent ? $object->subscription : ($object->parent?->subscription_details?->subscription ?? $object->subscription ?? null));
        if (! is_string($id)) {
            return true;
        }
        DB::transaction(function () use ($event, $id, $object) {
            // Serialize by customer, then retrieve current Stripe state: delivery order cannot regress status.
            $user = User::where('stripe_customer_id', $object->customer)->lockForUpdate()->first();
            if (! $user) {
                throw new \RuntimeException('Subscription customer registration not ready.');
            }
            if (! DB::table('stripe_webhook_events')->insertOrIgnore(['provider_event_id' => $event->id,
                'type' => $event->type, 'processed_at' => now()])) {
                return;
            }
            $remote = $this->stripe->retrieve($id);
            app(StripeEnvironment::class)->assertObject($remote);
            if ($remote->customer !== $user->stripe_customer_id) {
                return;
            }
            $localId = $remote->metadata->feitoo_subscription ?? null;
            $local = Subscription::where('user_id', $user->id)->whereKey($localId)->lockForUpdate()->first();
            $item = $remote->items->data[0] ?? null;
            if (! $local || count($remote->items->data) !== 1 || $item?->price?->id !== $local->provider_price_id
                || ($local->provider_subscription_id && $local->provider_subscription_id !== $remote->id)) {
                return;
            }
            if ($item->quantity !== 1 || $item->price->unit_amount !== ($local->contract_amount ?? (int) config('monetization.pro.amount'))
                || $item->price->currency !== ($local->contract_currency ?? 'brl')) {
                throw new \RuntimeException('Subscription item does not match its contract.');
            }
            $status = SubscriptionStatus::tryFrom($remote->status);
            if (! $status) {
                throw new \RuntimeException('Unknown subscription status.');
            }
            // With delayed methods Stripe can report active while the invoice is still unpaid (or void).
            // Never use completion of Checkout, or a stale invoice event, as financial confirmation.
            $invoice = $remote->latest_invoice;
            if ($status === SubscriptionStatus::Active) {
                if ($remote->collection_method !== 'charge_automatically'
                    || ! is_object($invoice) || $invoice->status !== 'paid') {
                    $status = in_array($local->status, [SubscriptionStatus::Active, SubscriptionStatus::PastDue, SubscriptionStatus::Unpaid], true)
                        ? SubscriptionStatus::PastDue : SubscriptionStatus::Incomplete;
                } else {
                    app(StripeEnvironment::class)->assertObject($invoice);
                    $invoiceSubscription = $invoice->parent?->subscription_details?->subscription ?? $invoice->subscription;
                    if ($invoice->customer !== $local->provider_customer_id || $invoiceSubscription !== $remote->id
                        || $invoice->currency !== ($local->contract_currency ?? 'brl')
                        || $invoice->total !== ($local->contract_amount ?? (int) config('monetization.pro.amount'))) {
                        throw new \RuntimeException('Subscription payment does not match its contract.');
                    }
                }
            }
            $local->update(['provider_subscription_id' => $remote->id, 'status' => $status,
                'contract_amount' => $local->contract_amount ?? $item->price->unit_amount,
                'contract_currency' => $local->contract_currency ?? $item->price->currency,
                'current_period_start' => isset($item->current_period_start) ? Carbon::createFromTimestamp($item->current_period_start) : null,
                'current_period_end' => isset($item->current_period_end) ? Carbon::createFromTimestamp($item->current_period_end) : null,
                'cancel_at_period_end' => (bool) $remote->cancel_at_period_end,
                'canceled_at' => $remote->canceled_at ? Carbon::createFromTimestamp($remote->canceled_at) : null,
                'checkout_url' => null]);
        });

        return true;
    }
}
