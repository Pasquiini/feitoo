<?php

namespace App\Services;

use App\Exceptions\BillingException;
use App\Models\Processing;
use App\Models\Purchase;
use Stripe\Checkout\Session;
use Stripe\HttpClient\CurlClient;
use Stripe\StripeClient;

class StripeCheckoutGateway
{
    public function available(): bool
    {
        return str_starts_with((string) config('monetization.stripe.secret_key'), 'sk_test_')
            && str_starts_with((string) config('monetization.stripe.webhook_secret'), 'whsec_');
    }

    private function client(): StripeClient
    {
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
            'mode' => 'payment', 'payment_method_types' => ['card'],
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
        return $this->client()->checkout->sessions->create($this->parameters($purchase, $processing), ['idempotency_key' => 'purchase-'.$purchase->uuid]);
    }

    public function expire(string $session): Session
    {
        $client = $this->client();
        $current = $client->checkout->sessions->retrieve($session);

        return $current->status === 'expired' ? $current : $client->checkout->sessions->expire($session);
    }
}
