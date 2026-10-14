<?php

namespace App\Services;

use App\Exceptions\BillingException;

class StripeEnvironment
{
    public function available(): bool
    {
        $mode = config('monetization.stripe.mode');

        return in_array($mode, ['test', 'live'], true)
            && str_starts_with((string) config('monetization.stripe.secret_key'), 'sk_'.$mode.'_')
            && str_starts_with((string) config('monetization.stripe.webhook_secret'), 'whsec_');
    }

    public function assertConfigured(): void
    {
        if (! $this->available()) {
            throw new BillingException('stripe_configuration_invalid', 'Configuracao Stripe incompativel: verifique STRIPE_MODE, a chave e o webhook.', 503);
        }
    }

    public function assertObject(object $object, int $status = 503): void
    {
        $this->assertConfigured();
        if (($object->livemode ?? null) !== (config('monetization.stripe.mode') === 'live')) {
            throw new BillingException('stripe_mode_mismatch', 'O ambiente do objeto Stripe nao corresponde ao STRIPE_MODE configurado.', $status);
        }
    }

    public function assertSession(object $session, bool $redirect = false): void
    {
        $this->assertObject($session);
        if (! str_starts_with((string) $session->id, 'cs_'.config('monetization.stripe.mode').'_')
            || ($redirect && ! preg_match('~^https://checkout\.stripe\.com/~', (string) $session->url))) {
            throw new BillingException('stripe_mode_mismatch', 'Checkout Stripe incompativel com o ambiente configurado.', 503);
        }
    }
}
