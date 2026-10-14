<?php

namespace App\Http\Controllers;

use App\Services\StripeWebhookService;
use Illuminate\Http\Request;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use UnexpectedValueException;

class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, StripeWebhookService $service)
    {
        $secret = (string) config('monetization.stripe.webhook_secret');
        abort_unless(str_starts_with($secret, 'whsec_'), 503);
        abort_if(strlen($request->getContent()) > 1048576, 413);
        try {
            $event = Webhook::constructEvent($request->getContent(), (string) $request->header('Stripe-Signature'), $secret);
        } catch (SignatureVerificationException|UnexpectedValueException) {
            return response()->json(['message' => 'Assinatura Stripe inválida.'], 400);
        }
        abort_unless($event->livemode === false && is_string($event->id) && strlen($event->id) <= 255, 400);
        $service->handle($event);

        return response()->json(['received' => true]);
    }
}
