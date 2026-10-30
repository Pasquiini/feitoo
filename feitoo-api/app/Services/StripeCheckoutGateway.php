<?php

namespace App\Services;

use App\Exceptions\BillingException;
use App\Models\Processing;
use App\Models\Purchase;
use Stripe\Checkout\Session;
use Stripe\Collection;
use Stripe\HttpClient\CurlClient;
use Stripe\PaymentIntent;
use Stripe\StripeClient;

class StripeCheckoutGateway
{
    public function available(): bool
    {
        return app(StripeEnvironment::class)->available();
    }

    protected function client(): StripeClient
    {
        app(StripeEnvironment::class)->assertConfigured();
        if (! $this->available()) {
            throw new BillingException('checkout_unavailable', 'A compra avulsa ainda não está disponível. Tente novamente mais tarde.', 503);
        }
        CurlClient::instance()->setConnectTimeout(5);
        CurlClient::instance()->setTimeout(15);

        return new StripeClient(['api_key' => config('monetization.stripe.secret_key'), 'max_network_retries' => 1]);
    }

    public function parameters(Purchase $purchase, Processing $processing): array
    {
        $frontend = rtrim(config('feitoo.auth.frontend_url'), '/');
        $metadata = ['purchase_uuid' => $purchase->uuid, 'processing_uuid' => $processing->uuid, 'package_code' => $purchase->package_code];

        return [
            'mode' => 'payment',
            'expires_at' => $purchase->expires_at->timestamp,
            'success_url' => $frontend.$processing->tool->path().'?processing='.$processing->uuid.'&payment=success',
            'cancel_url' => $frontend.$processing->tool->path().'?processing='.$processing->uuid.'&payment=cancelled',
            'client_reference_id' => $purchase->uuid, 'metadata' => $metadata,
            'payment_intent_data' => ['metadata' => $metadata],
            'line_items' => [['quantity' => 1, 'price_data' => [
                'currency' => $purchase->currency, 'unit_amount' => $purchase->amount,
                'product_data' => ['name' => 'Feitoo - '.$processing->tool->label(),
                    'description' => 'Um processamento de até '.$purchase->files_limit.' XMLs. Não gera saldo reutilizável.'],
            ]]],
        ];
    }

    public function create(Purchase $purchase, Processing $processing): Session
    {
        $session = $this->client()->checkout->sessions->create($this->parameters($purchase, $processing), ['idempotency_key' => 'purchase-'.$purchase->uuid]);
        app(StripeEnvironment::class)->assertSession($session, true);

        return $session;
    }

    public function session(string $id): Session
    {
        $session = $this->client()->checkout->sessions->retrieve($id, ['expand' => ['line_items.data.price']]);
        app(StripeEnvironment::class)->assertSession($session);

        return $session;
    }

    public function lineItems(string $session): Collection
    {
        return $this->client()->checkout->sessions->allLineItems($session, ['limit' => 2]);
    }

    public function paymentIntent(string $id): PaymentIntent
    {
        $intent = $this->client()->paymentIntents->retrieve($id);
        app(StripeEnvironment::class)->assertObject($intent);

        return $intent;
    }

    /** Bounded GET recovery: never infer remote absence from an empty local Session field. */
    public function findPurchaseSession(Purchase $purchase): ?Session
    {
        $sessions = $this->client()->checkout->sessions->all(['limit' => 100,
            'created' => ['gte' => $purchase->created_at->timestamp - 5, 'lte' => $purchase->expires_at->timestamp]]);
        $found = null;
        $scanned = 0;
        foreach ($sessions->autoPagingIterator() as $session) {
            if (++$scanned > 200) {
                throw new BillingException('checkout_unavailable', 'Consulte o pagamento novamente.', 503);
            }
            if ($session->client_reference_id !== $purchase->uuid || ($session->metadata->purchase_uuid ?? null) !== $purchase->uuid) {
                continue;
            }
            app(StripeEnvironment::class)->assertSession($session);
            if ($found) {
                throw new BillingException('payment_mismatch', 'Mais de uma sessão encontrada para a compra.', 422);
            }
            $found = $session;
        }

        return $found;
    }

    public function expire(string $session): Session
    {
        $client = $this->client();
        $current = $client->checkout->sessions->retrieve($session);

        app(StripeEnvironment::class)->assertSession($current);
        $expired = $current->status === 'expired' ? $current : $client->checkout->sessions->expire($session);
        app(StripeEnvironment::class)->assertSession($expired);

        return $expired;
    }
}
