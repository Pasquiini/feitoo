<?php

namespace App\Services;

use App\Enums\ProcessingStatus;
use App\Enums\ProcessingTool;
use App\Enums\PurchaseStatus;
use App\Exceptions\BillingException;
use App\Models\Processing;
use App\Models\Purchase;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class PurchaseService
{
    public function __construct(private readonly StripeCheckoutGateway $stripe) {}

    public function packages(): array
    {
        $packages = [];
        foreach (config('monetization.packages') as $code => $package) {
            if ($package['amount'] > 0 && $package['files_limit'] <= config('feitoo.uploads.max_processing_files')) {
                $packages[] = ['code' => $code, ...$package];
            }
        }
        usort($packages, fn ($a, $b) => $a['files_limit'] <=> $b['files_limit']);

        return $packages;
    }

    public function packageFor(int $files): array
    {
        foreach ($this->packages() as $package) {
            if ($package['files_limit'] >= $files) {
                return $package;
            }
        }
        throw new BillingException('technical_limit_exceeded', 'Esse volume ultrapassa a capacidade disponível da ferramenta.');
    }

    public function checkout(Processing $processing, User $owner): array
    {
        abort_if($processing->tool === ProcessingTool::QuotationGenerator, 422);
        abort_if(in_array($processing->tool, [ProcessingTool::DocxToPdf, ProcessingTool::XmlToPdf, ProcessingTool::XmlOrganizer, ProcessingTool::XmlAnalyzer], true), 422, 'Use os limites Free ou Pro para esta ferramenta.');
        app(StripeEnvironment::class)->assertConfigured();
        if (! $this->stripe->available()) {
            throw new BillingException('checkout_unavailable', 'A compra avulsa ainda não está disponível. Tente novamente mais tarde.', 503);
        }
        // Commit the immutable price/volume/expiry first. An ambiguous network failure can then
        // retry the same Purchase and Stripe idempotency key without creating another charge.
        $purchase = DB::transaction(function () use ($processing, $owner): Purchase {
            $locked = Processing::query()->lockForUpdate()->findOrFail($processing->id);
            abort_unless((int) $locked->user_id === (int) $owner->id, 404);
            abort_unless($locked->status === ProcessingStatus::Pending, 409);
            abort_if(! $locked->expires_at?->isFuture(), 410);
            abort_unless($locked->files_count > 0, 422);
            $usage = app(EntitlementService::class)->usage($owner);
            abort_if($usage['plan'] === 'pro' && $locked->files_count <= $usage['limits']['per_processing'], 409, 'Use seu plano Pro para este lote.');
            $existing = Purchase::query()->where('processing_id', $locked->id)->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }
            if ($locked->expires_at->timestamp < now()->addMinutes(31)->timestamp) {
                throw new BillingException('checkout_unavailable', 'O prazo deste lote está terminando. Envie seus arquivos novamente antes de comprar.');
            }
            $package = $this->packageFor($locked->files_count);

            return Purchase::create(['user_id' => $owner->id, 'processing_id' => $locked->id,
                'package_code' => $package['code'], 'files_limit' => $package['files_limit'], 'files_count' => $locked->files_count,
                'amount' => $package['amount'], 'currency' => $package['currency'], 'status' => PurchaseStatus::Pending,
                'expires_at' => min($locked->expires_at, now()->addMinutes(config('monetization.stripe.checkout_minutes')))]);
        });

        return DB::transaction(function () use ($processing, $purchase): array {
            $locked = Processing::query()->lockForUpdate()->findOrFail($processing->id);
            $purchase = Purchase::query()->lockForUpdate()->findOrFail($purchase->id);
            abort_unless($locked->status === ProcessingStatus::Pending && $locked->expires_at?->isFuture(), 409);
            if ($purchase->status === PurchaseStatus::Paid) {
                return ['purchase' => $purchase, 'checkout_url' => null];
            }
            if (in_array($purchase->status, [PurchaseStatus::Expired, PurchaseStatus::Refunded], true) || ! $purchase->expires_at->isFuture()) {
                throw new BillingException('purchase_expired', 'Este Checkout terminou. Você pode usar o Free ou reenviar o lote para uma nova compra.');
            }
            if (! $purchase->provider_checkout_session_id) {
                try {
                    $session = $this->stripe->create($purchase, $locked);
                    app(StripeEnvironment::class)->assertSession($session, true);
                    $purchase->update(['provider_checkout_session_id' => $session->id, 'checkout_url' => $session->url]);
                } catch (BillingException $exception) {
                    throw $exception;
                } catch (Throwable $exception) {
                    Log::warning('Checkout creation failed.', ['exception_type' => $exception::class]);
                    throw new BillingException('checkout_unavailable', 'Não foi possível abrir o Checkout. Tente novamente.', 503);
                }
            } else {
                app(StripeEnvironment::class)->assertSession($this->stripe->session($purchase->provider_checkout_session_id), true);
            }

            return ['purchase' => $purchase, 'checkout_url' => $purchase->checkout_url];
        });
    }

    public function cancel(Processing $processing, User $owner): Purchase
    {
        return DB::transaction(function () use ($processing, $owner): Purchase {
            $locked = Processing::query()->lockForUpdate()->findOrFail($processing->id);
            abort_unless((int) $locked->user_id === (int) $owner->id, 404);
            abort_unless($locked->status === ProcessingStatus::Pending, 409);
            $purchase = Purchase::query()->where('processing_id', $locked->id)->lockForUpdate()->firstOrFail();
            if ($purchase->awaitingPayment()) {
                throw new BillingException('payment_pending', 'O pagamento aguarda confirmação. Consulte novamente antes de continuar.');
            }
            if (in_array($purchase->status, [PurchaseStatus::Paid, PurchaseStatus::Refunded], true)) {
                throw new BillingException('payment_already_confirmed', 'Consulte a confirmação do pagamento antes de continuar.');
            }
            if (! $purchase->checkout_closed_at && $purchase->provider_checkout_session_id) {
                try {
                    $session = $this->stripe->expire($purchase->provider_checkout_session_id);
                    app(StripeEnvironment::class)->assertSession($session);
                    if ($session->status !== 'expired') {
                        throw new BillingException('checkout_unavailable', 'Não foi possível cancelar o Checkout.');
                    }
                } catch (BillingException $exception) {
                    throw $exception;
                } catch (Throwable $exception) {
                    Log::warning('Checkout cancellation failed.', ['exception_type' => $exception::class]);
                    throw new BillingException('payment_pending', 'Não foi possível cancelar o Checkout. Consulte o pagamento ou tente novamente.');
                }
            } elseif (! $purchase->checkout_closed_at && ! $purchase->provider_checkout_session_id) {
                // A timed-out create may still exist at Stripe. Recover it with the SAME key
                // before cancellation, never allow a free start while payment is uncertain.
                $session = $this->stripe->create($purchase, $locked);
                $expired = $this->stripe->expire($session->id);
                app(StripeEnvironment::class)->assertSession($session);
                app(StripeEnvironment::class)->assertSession($expired);
                abort_unless($expired->status === 'expired', 409);
                $purchase->provider_checkout_session_id = $session->id;
            }
            $purchase->status = PurchaseStatus::Expired;
            $purchase->checkout_closed_at = now();
            $purchase->checkout_url = null;
            $purchase->save();

            return $purchase;
        });
    }

    public function refresh(Processing $processing): Purchase
    {
        $purchase = Purchase::where('processing_id', $processing->id)->firstOrFail();
        if (! in_array($purchase->status, [PurchaseStatus::Pending, PurchaseStatus::Failed, PurchaseStatus::Expired], true)) {
            return $purchase;
        }
        $session = $purchase->provider_checkout_session_id ? $this->stripe->session($purchase->provider_checkout_session_id) : $this->stripe->findPurchaseSession($purchase);
        if (! $session) {
            return $purchase;
        }

        return DB::transaction(function () use ($processing, $purchase, $session): Purchase {
            $locked = Processing::whereKey($processing->id)->lockForUpdate()->firstOrFail();
            $purchase = Purchase::whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            $this->applySession($purchase, $locked, $session);

            return $purchase;
        }, 5);
    }

    /** Caller holds Processing then Purchase locks, shared by signed webhooks and read-only reconciliation. */
    public function applySession(Purchase $purchase, Processing $processing, object $session, ?string $eventType = null): void
    {
        app(StripeEnvironment::class)->assertSession($session);
        if (($purchase->provider_checkout_session_id && $session->id !== $purchase->provider_checkout_session_id) || $session->mode !== 'payment'
            || $session->client_reference_id !== $purchase->uuid
            || ($session->metadata->purchase_uuid ?? null) !== $purchase->uuid
            || ($session->metadata->processing_uuid ?? null) !== $processing->uuid
            || ($session->metadata->package_code ?? null) !== $purchase->package_code
            || (int) $purchase->user_id !== (int) $processing->user_id
            || $session->amount_total !== $purchase->amount || $session->currency !== $purchase->currency
            || ($purchase->provider_payment_intent_id && $session->payment_intent !== $purchase->provider_payment_intent_id)) {
            throw new BillingException('payment_mismatch', 'O pagamento não corresponde à compra.', 422);
        }
        if (! $purchase->provider_checkout_session_id) {
            $purchase->update(['provider_checkout_session_id' => $session->id]);
        }
        if ($purchase->status === PurchaseStatus::Refunded) {
            return;
        }
        if ($session->payment_status === 'paid' && is_string($session->payment_intent)) {
            $items = $session->line_items ?? $this->stripe->lineItems($session->id);
            $item = $items->data[0] ?? null;
            $price = $item?->price;
            // Fiscal purchases use inline price_data; their immutable contract is stored on Purchase.
            if (count($items->data) !== 1 || $items->has_more || $item->quantity !== 1
                || $price?->unit_amount !== $purchase->amount || $price->currency !== $purchase->currency
                || $price->livemode !== (config('monetization.stripe.mode') === 'live')) {
                throw new BillingException('payment_mismatch', 'O item pago não corresponde à compra.', 422);
            }
            if ($processing->status === ProcessingStatus::Pending && ($purchase->awaitingPayment() || $processing->expires_at->isPast())) {
                $until = now()->addMinutes((int) config('feitoo.processing.ttl_minutes'));
                $processing->update(['expires_at' => $until]);
                $processing->files()->whereNull('removed_at')->update(['expires_at' => $until]);
            }
            $purchase->update(['status' => PurchaseStatus::Paid, 'paid_at' => $purchase->paid_at ?? now(),
                'provider_payment_intent_id' => $session->payment_intent, 'checkout_url' => null]);

            return;
        }
        if ($purchase->status === PurchaseStatus::Paid) {
            return; // Late failure, expiration or unpaid completion cannot undo a confirmed payment.
        }
        if ($purchase->status === PurchaseStatus::Failed && $eventType === 'checkout.session.completed') {
            return;
        }
        if ($eventType === 'checkout.session.async_payment_failed') {
            $purchase->update(['status' => PurchaseStatus::Failed, 'checkout_url' => null, 'checkout_closed_at' => now()]);
        } elseif ($session->status === 'expired') {
            $purchase->update(['status' => PurchaseStatus::Expired, 'checkout_url' => null, 'checkout_closed_at' => now()]);
        } elseif ($session->status === 'complete' && is_string($session->payment_intent)) {
            $intent = $this->stripe->paymentIntent($session->payment_intent);
            if ($intent->id !== $session->payment_intent || $intent->amount !== $purchase->amount || $intent->currency !== $purchase->currency
                || ($intent->metadata->purchase_uuid ?? null) !== $purchase->uuid
                || ($intent->metadata->processing_uuid ?? null) !== $processing->uuid
                || ($intent->metadata->package_code ?? null) !== $purchase->package_code) {
                throw new BillingException('payment_mismatch', 'O pagamento pendente não corresponde à compra.', 422);
            }
            if (in_array($intent->status, ['requires_payment_method', 'canceled'], true)) {
                $purchase->update(['status' => PurchaseStatus::Failed, 'provider_payment_intent_id' => $session->payment_intent,
                    'checkout_url' => null, 'checkout_closed_at' => now()]);

                return;
            }
            // Keep the original Checkout expiry/key immutable. Only uploaded inputs await settlement.
            // Stripe supplies the voucher/QR deadline; allow three business days for settlement.
            $deadline = $intent->next_action?->boleto_display_details?->expires_at
                ?? $intent->next_action?->pix_display_qr_code?->expires_at;
            $until = is_int($deadline) ? Carbon::createFromTimestamp($deadline)->addWeekdays(3) : now()->addDays(7);
            if ($purchase->awaitingPayment()) {
                // Recheck settlement before a cleanup deadline; never discard inputs while Stripe is still processing.
                $until = $processing->expires_at->isPast() ? now()->addDay() : $processing->expires_at;
            }
            if ($processing->status === ProcessingStatus::Pending && $processing->expires_at->lessThan($until)) {
                $processing->update(['expires_at' => $until]);
                $processing->files()->whereNull('removed_at')->update(['expires_at' => $until]);
            }
            $purchase->update(['status' => PurchaseStatus::Pending, 'provider_payment_intent_id' => $session->payment_intent,
                'checkout_url' => null, 'checkout_closed_at' => $purchase->checkout_closed_at ?? now()]);
        }
    }
}
