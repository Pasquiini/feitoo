<?php

namespace App\Services\Quotations;

use App\Exceptions\QuotationGenerationException;
use App\Models\QuotationCreditOrder;
use App\Services\StripeCheckoutGateway;
use App\Services\StripeEnvironment;
use Stripe\Checkout\Session;
use Stripe\HttpClient\CurlClient;
use Stripe\StripeClient;

class QuotationStripeGateway extends StripeCheckoutGateway
{
    protected function client(): StripeClient
    {
        app(StripeEnvironment::class)->assertConfigured();
        if (config('monetization.stripe.mode') !== 'test') {
            throw new QuotationGenerationException('checkout_unavailable', 503);
        }
        CurlClient::instance()->setConnectTimeout(5);
        CurlClient::instance()->setTimeout(15);

        return new StripeClient(['api_key' => config('monetization.stripe.secret_key'), 'max_network_retries' => 1]);
    }

    public function available(): bool
    {
        return config('monetization.stripe.mode') === 'test' && parent::available()
            && str_starts_with((string) config('quotations.credit_price_id'), 'price_');
    }

    public function checkout(QuotationCreditOrder $order): Session
    {
        if (! $this->available()) {
            throw new QuotationGenerationException('checkout_unavailable', 503);
        }
        $client = $this->client();
        $price = $client->prices->retrieve($order->price_id);
        app(StripeEnvironment::class)->assertObject($price);
        if (! $price->active || $price->currency !== 'brl' || $price->unit_amount !== 990 || $price->type !== 'one_time') {
            throw new QuotationGenerationException('checkout_unavailable', 503);
        }
        $metadata = ['quotation_order' => $order->uuid, 'operation' => 'quotation_credits'];
        $base = rtrim(config('feitoo.auth.frontend_url'), '/').'/gerador-de-orcamentos?quotation_order='.$order->uuid;
        $session = $client->checkout->sessions->create(['mode' => 'payment', 'payment_method_types' => ['card'],
            'client_reference_id' => $order->uuid, 'metadata' => $metadata, 'payment_intent_data' => ['metadata' => $metadata],
            'line_items' => [['price' => $order->price_id, 'quantity' => 1]], 'expires_at' => $order->expires_at->timestamp,
            'success_url' => $base.'&payment=confirming', 'cancel_url' => $base.'&payment=cancelled'],
            ['idempotency_key' => 'quotation-order-'.$order->uuid]);
        app(StripeEnvironment::class)->assertSession($session, true);

        return $session;
    }

    public function orderForIntent(string $id): ?string
    {
        $intent = $this->client()->paymentIntents->retrieve($id);
        app(StripeEnvironment::class)->assertObject($intent);

        return $intent->metadata->quotation_order ?? null;
    }
}
