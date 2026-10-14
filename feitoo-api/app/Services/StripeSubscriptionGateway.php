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

    protected function client(): StripeClient
    {
        app(StripeEnvironment::class)->assertConfigured();
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
        app(StripeEnvironment::class)->assertObject($customer);

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
        app(StripeEnvironment::class)->assertObject($price);
        $this->validateCustomer($subscription->provider_customer_id);
        abort_unless($price->active && $price->currency === 'brl'
            && $price->unit_amount === (int) config('monetization.pro.amount')
            && $price->recurring?->interval === 'month' && $price->recurring?->interval_count === 1, 503);

        $session = $client->checkout->sessions->create($this->parameters($subscription), ['idempotency_key' => 'feitoo-pro-'.$subscription->id]);
        app(StripeEnvironment::class)->assertSession($session, true);

        return $session;
    }

    public function retrieve(string $id): \Stripe\Subscription
    {
        $subscription = $this->client()->subscriptions->retrieve($id);
        app(StripeEnvironment::class)->assertObject($subscription);

        return $subscription;
    }

    public function session(string $id): Session
    {
        $session = $this->client()->checkout->sessions->retrieve($id);
        app(StripeEnvironment::class)->assertSession($session);

        return $session;
    }

    public function validateCustomer(string $id): void
    {
        $customer = $this->client()->customers->retrieve($id);
        app(StripeEnvironment::class)->assertObject($customer);
        abort_if($customer->deleted ?? false, 503, 'Customer Stripe indisponivel.');
    }

    public function portal(string $customer): string
    {
        $this->validateCustomer($customer);
        $session = $this->client()->billingPortal->sessions->create(['customer' => $customer,
            'return_url' => rtrim(config('feitoo.auth.frontend_url'), '/').'/conta']);
        app(StripeEnvironment::class)->assertObject($session);
        abort_unless($session->customer === $customer && preg_match('~^https://billing\.stripe\.com/~', (string) $session->url), 503);

        return $session->url;
    }
}
