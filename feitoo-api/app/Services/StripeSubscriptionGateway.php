<?php

namespace App\Services;

use App\Exceptions\BillingException;
use App\Models\Subscription;
use App\Models\User;
use Stripe\Checkout\Session;
use Stripe\HttpClient\CurlClient;
use Stripe\StripeClient;

class StripeSubscriptionGateway
{
    public function available(): bool
    {
        return app(StripeCheckoutGateway::class)->available()
            && str_starts_with((string) config('monetization.pro.price_id'), 'price_');
    }

    private function client(): StripeClient
    {
        if (! $this->available()) {
            throw new BillingException('checkout_unavailable', 'A assinatura Pro ainda não está disponível.', 503);
        }
        CurlClient::instance()->setConnectTimeout(5);
        CurlClient::instance()->setTimeout(15);

        return new StripeClient(['api_key' => config('monetization.stripe.secret_key'), 'max_network_retries' => 1]);
    }

    public function customer(User $user): string
    {
        $customer = $this->client()->customers->create([], ['idempotency_key' => 'feitoo-customer-'.$user->id]);
        abort_unless($customer->livemode === false, 503);

        return $customer->id;
    }

    public function parameters(Subscription $subscription): array
    {
        $frontend = rtrim(config('feitoo.auth.frontend_url'), '/');

        return ['mode' => 'subscription', 'customer' => $subscription->provider_customer_id,
            'line_items' => [['price' => $subscription->provider_price_id, 'quantity' => 1]],
            'success_url' => $frontend.'/conta?subscription=confirming',
            'cancel_url' => $frontend.'/conta?subscription=cancelled',
            'subscription_data' => ['metadata' => ['feitoo_subscription' => (string) $subscription->id]],
            'metadata' => ['feitoo_subscription' => (string) $subscription->id]];
    }

    public function checkout(Subscription $subscription): Session
    {
        $client = $this->client();
        $price = $client->prices->retrieve($subscription->provider_price_id);
        abort_unless($price->livemode === false && $price->active && $price->currency === 'brl'
            && $price->unit_amount === (int) config('monetization.pro.amount')
            && $price->recurring?->interval === 'month' && $price->recurring?->interval_count === 1, 503);

        return $client->checkout->sessions->create($this->parameters($subscription), ['idempotency_key' => 'feitoo-pro-'.$subscription->id]);
    }

    public function retrieve(string $id): \Stripe\Subscription
    {
        return $this->client()->subscriptions->retrieve($id);
    }

    public function session(string $id): Session
    {
        return $this->client()->checkout->sessions->retrieve($id);
    }

    public function portal(string $customer): string
    {
        return $this->client()->billingPortal->sessions->create(['customer' => $customer,
            'return_url' => rtrim(config('feitoo.auth.frontend_url'), '/').'/conta'])->url;
    }
}
