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
        CurlClient::instance()->setConnectTimeout(5);
        CurlClient::instance()->setTimeout(15);

        return new StripeClient(['api_key' => config('monetization.stripe.secret_key'), 'max_network_retries' => 1]);
    }

    public function available(): bool
    {
        return (bool) config('quotations.purchases_enabled') && parent::available()
            && str_starts_with((string) config('quotations.credit_price_id'), 'price_');
    }

    public function checkout(QuotationCreditOrder $order): Session
    {
        if (! $this->available()) {
            throw new QuotationGenerationException('checkout_unavailable', 503);
        }
        $client = $this->client();
        if ($order->stripe_mode !== config('monetization.stripe.mode')) {
            throw new QuotationGenerationException('payment_mismatch', 422);
        }
        $this->verifyPrice($order->price_id);
        $metadata = ['quotation_order' => $order->uuid, 'operation' => 'quotation_credits'];
        $path = $order->return_destination === 'account' ? '/conta/creditos' : '/gerador-de-orcamentos';
        $base = rtrim(config('feitoo.auth.frontend_url'), '/').$path.'?quotation_order='.$order->uuid;
        $session = $client->checkout->sessions->create(['mode' => 'payment', 'payment_method_types' => ['card'],
            'adaptive_pricing' => ['enabled' => false], 'automatic_tax' => ['enabled' => false], 'allow_promotion_codes' => false,
            'client_reference_id' => $order->uuid, 'metadata' => $metadata, 'payment_intent_data' => ['metadata' => $metadata],
            'line_items' => [['price' => $order->price_id, 'quantity' => 1]], 'expires_at' => $order->expires_at->timestamp,
            'success_url' => $base.'&payment=confirming', 'cancel_url' => $base.'&payment=cancelled'],
            ['idempotency_key' => 'quotation-order-'.$order->uuid]);
        app(StripeEnvironment::class)->assertSession($session, true);

        return $session;
    }

    /** Read-only preflight, also available with new purchases disabled. */
    public function verifyPrice(string $id): void
    {
        $price = $this->client()->prices->retrieve($id, ['expand' => ['product']]);
        app(StripeEnvironment::class)->assertObject($price);
        $product = $price->product;
        if (! is_object($product) || ! $product->active || ($product->metadata->code ?? null) !== 'quotation_5'
            || ($product->metadata->credits ?? null) !== '5'
            || ! $price->active || $price->currency !== 'brl' || $price->unit_amount !== 990 || $price->type !== 'one_time') {
            throw new QuotationGenerationException('checkout_unavailable', 503);
        }
        app(StripeEnvironment::class)->assertObject($product);
    }

    public function orderForIntent(string $id): ?string
    {
        $intent = $this->client()->paymentIntents->retrieve($id);
        app(StripeEnvironment::class)->assertObject($intent);

        return ($intent->metadata->operation ?? null) === 'quotation_credits' ? ($intent->metadata->quotation_order ?? null) : null;
    }

    public function dispute(string $id): object
    {
        $dispute = $this->client()->disputes->retrieve($id);
        app(StripeEnvironment::class)->assertObject($dispute);

        return $dispute;
    }

    /** Recover an ambiguous creation using GET only. Never recreate an old idempotency key. */
    public function findSession(QuotationCreditOrder $order): ?Session
    {
        if ($order->stripe_mode !== config('monetization.stripe.mode')) {
            throw new QuotationGenerationException('payment_mismatch', 422);
        }
        $sessions = $this->client()->checkout->sessions->all(['limit' => 100,
            'created' => ['gte' => $order->created_at->timestamp - 5, 'lte' => $order->expires_at->timestamp]]);
        $found = null;
        $scanned = 0;
        foreach ($sessions->autoPagingIterator() as $session) {
            if (++$scanned > 200) {
                throw new QuotationGenerationException('checkout_unavailable', 503);
            }
            if (($session->metadata->quotation_order ?? null) !== $order->uuid
                || ($session->metadata->operation ?? null) !== 'quotation_credits' || $session->client_reference_id !== $order->uuid) {
                continue;
            }
            app(StripeEnvironment::class)->assertSession($session);
            if ($found) {
                throw new QuotationGenerationException('payment_mismatch', 422);
            }
            $found = $session;
        }

        return $found;
    }
}
