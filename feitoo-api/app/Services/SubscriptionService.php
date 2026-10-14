<?php

namespace App\Services;

use App\Enums\SubscriptionStatus;
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

        return ['offer' => ['amount' => (int) config('monetization.pro.amount'), 'currency' => 'brl',
            'per_processing' => min((int) config('monetization.pro.per_processing'), (int) config('feitoo.uploads.max_processing_files')),
            'daily' => (int) config('monetization.pro.daily'), 'available' => $this->stripe->available()],
            'subscription' => $subscription ? ['status' => $subscription->status->value,
                'current_period_end' => $subscription->current_period_end?->toIso8601String(),
                'cancel_at_period_end' => $subscription->cancel_at_period_end,
                'grants_pro' => $subscription->grantsPro()] : null];
    }

    public function checkout(User $owner): string
    {
        abort_unless($this->stripe->available(), 503);

        $subscription = DB::transaction(function () use ($owner) {
            $user = User::whereKey($owner->id)->lockForUpdate()->firstOrFail();
            if (! $user->stripe_customer_id) {
                $user->forceFill(['stripe_customer_id' => $this->stripe->customer($user)])->save();
            }
            $subscription = $this->current($user);
            abort_if($subscription?->provider_subscription_id && $subscription->status === SubscriptionStatus::Incomplete, 409, 'Gerencie o pagamento da assinatura existente.');
            abort_if($subscription && ! in_array($subscription->status, [SubscriptionStatus::Canceled, SubscriptionStatus::IncompleteExpired, SubscriptionStatus::Incomplete], true), 409, 'Gerencie sua assinatura existente.');
            if ($subscription?->checkout_url && $subscription->checkout_expires_at?->isFuture()) {
                return $subscription;
            }
            if ($subscription?->checkout_session_id && ! $subscription->provider_subscription_id) {
                $session = $this->stripe->session($subscription->checkout_session_id);
                abort_unless($session->status === 'expired', 409, 'Aguardando confirmação da assinatura existente.');
            }
            if (! $subscription || $subscription->provider_subscription_id || $subscription->checkout_session_id) {
                $subscription = Subscription::create(['user_id' => $user->id, 'provider_customer_id' => $user->stripe_customer_id,
                    'provider_price_id' => config('monetization.pro.price_id')]);
            }

            return $subscription;
        });

        // Commit the attempt first, so ambiguous network failures retry the same Stripe idempotency key.
        return DB::transaction(function () use ($subscription, $owner) {
            User::whereKey($owner->id)->lockForUpdate()->firstOrFail();
            $subscription = Subscription::whereKey($subscription->id)->lockForUpdate()->firstOrFail();
            abort_if($subscription->provider_subscription_id, 409, 'Gerencie sua assinatura existente.');
            if ($subscription->checkout_url && $subscription->checkout_expires_at?->isFuture()) {
                return $subscription->checkout_url;
            }
            $session = $this->stripe->checkout($subscription);
            abort_unless($session->livemode === false && str_starts_with($session->id, 'cs_test_')
                && preg_match('~^https://checkout\.stripe\.com/~', (string) $session->url), 503);
            $subscription->update(['checkout_session_id' => $session->id, 'checkout_url' => $session->url,
                'checkout_expires_at' => Carbon::createFromTimestamp($session->expires_at)]);

            return $session->url;
        });
    }

    public function portal(User $user): string
    {
        abort_unless($user->stripe_customer_id && $this->current($user), 404);

        return $this->stripe->portal($user->stripe_customer_id);
    }

    public function handle(Event $event): bool
    {
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
            if ($remote->livemode !== false || $remote->customer !== $user->stripe_customer_id) {
                return;
            }
            $localId = $remote->metadata->feitoo_subscription ?? null;
            $local = Subscription::where('user_id', $user->id)->whereKey($localId)->lockForUpdate()->first();
            $item = $remote->items->data[0] ?? null;
            if (! $local || count($remote->items->data) !== 1 || $item?->price?->id !== $local->provider_price_id
                || ($local->provider_subscription_id && $local->provider_subscription_id !== $remote->id)) {
                return;
            }
            $status = SubscriptionStatus::tryFrom($remote->status);
            if (! $status) {
                throw new \RuntimeException('Unknown subscription status.');
            }
            $local->update(['provider_subscription_id' => $remote->id, 'status' => $status,
                'current_period_start' => isset($item->current_period_start) ? Carbon::createFromTimestamp($item->current_period_start) : null,
                'current_period_end' => isset($item->current_period_end) ? Carbon::createFromTimestamp($item->current_period_end) : null,
                'cancel_at_period_end' => (bool) $remote->cancel_at_period_end,
                'canceled_at' => $remote->canceled_at ? Carbon::createFromTimestamp($remote->canceled_at) : null,
                'checkout_url' => null]);
        });

        return true;
    }
}
