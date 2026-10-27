<?php

namespace Tests\Feature;

use App\Exceptions\QuotationGenerationException;
use App\Models\QuotationCreditOrder;
use App\Models\QuotationDraft;
use App\Models\QuotationGeneration;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Quotations\QuotationGenerationService;
use App\Services\Quotations\QuotationOrderService;
use App\Services\Quotations\QuotationPdf;
use App\Services\Quotations\QuotationStripeGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Stripe\ApiRequestor;
use Stripe\Checkout\Session;
use Stripe\HttpClient\ClientInterface;
use Tests\TestCase;

class QuotationCommerceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private QuotationGenerationService $generation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::getDriverName());
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->freezeSecond();
        config(['quotations.enabled' => true, 'quotations.sandbox' => 'php', 'quotations.credit_price_id' => 'price_test_fixture',
            'monetization.stripe.mode' => 'test', 'monetization.stripe.secret_key' => 'sk_test_fixture', 'monetization.stripe.webhook_secret' => 'whsec_fixture']);
        Storage::fake('quotation_drafts');
        Storage::fake('quotation_generations');
        ApiRequestor::setHttpClient(Mockery::mock(ClientInterface::class));
        $this->owner = User::factory()->create();
        $this->actingAs($this->owner, 'web');
        $this->generation = app(QuotationGenerationService::class);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        parent::tearDown();
    }

    private function draft(): QuotationDraft
    {
        $party = ['name' => 'Fixture', 'document' => '', 'email' => '', 'phone' => '', 'address' => ''];

        return QuotationDraft::create(['uuid' => (string) Str::uuid(), 'user_id' => $this->owner->id,
            'import_key' => (string) Str::uuid(), 'import_hash' => str_repeat('a', 64), 'schema_version' => 1, 'revision' => 1,
            'payload' => ['number' => 'TEST', 'issue_date' => '2026-10-24', 'valid_until' => '2026-11-24', 'issuer' => $party, 'customer' => $party,
                'items' => [['description' => 'Fixture', 'quantity' => '1', 'unit_price_cents' => 100]], 'discount_type' => 'fixed',
                'discount_fixed_cents' => 0, 'discount_percent' => '0', 'conditions' => '', 'notes' => '', 'template_id' => 'modern'],
            'expires_at' => now()->addDay()]);
    }

    private function order(): QuotationCreditOrder
    {
        return QuotationCreditOrder::create(['uuid' => (string) Str::uuid(), 'user_id' => $this->owner->id,
            'idempotency_key' => (string) Str::uuid(), 'package_code' => 'quotation_5', 'amount' => 990, 'credits' => 5,
            'currency' => 'brl', 'price_id' => 'price_test_fixture', 'expires_at' => now()->addHour(),
            'session_id' => 'cs_test_'.Str::random(20)]);
    }

    private function checkoutSession(QuotationCreditOrder $order, array $overrides = []): Session
    {
        return Session::constructFrom(array_replace(['id' => $order->session_id, 'object' => 'checkout.session', 'livemode' => false,
            'mode' => 'payment', 'status' => 'complete', 'client_reference_id' => $order->uuid, 'amount_total' => 990, 'currency' => 'brl',
            'payment_status' => 'paid', 'payment_intent' => 'pi_'.$order->uuid,
            'metadata' => ['quotation_order' => $order->uuid, 'operation' => 'quotation_credits']], $overrides));
    }

    private function signed(string $type, object $object, ?string $id = null)
    {
        $payload = json_encode(['id' => $id ?? 'evt_'.Str::random(20), 'object' => 'event', 'type' => $type, 'livemode' => false,
            'data' => ['object' => $object]], JSON_THROW_ON_ERROR);
        $time = time();
        $signature = 't='.$time.',v1='.hash_hmac('sha256', $time.'.'.$payload, 'whsec_fixture');

        return $this->call('POST', '/api/stripe/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signature], $payload);
    }

    private function grant(int $credits = 5): QuotationCreditOrder
    {
        $order = $this->order();
        $this->signed('checkout.session.completed', $this->checkoutSession($order))->assertOk();
        DB::table('quotation_credit_grants')->where('order_id', $order->id)->update(['granted' => $credits]);

        return $order->fresh();
    }

    private function start(string $source = 'credit', ?QuotationDraft $draft = null, ?string $key = null)
    {
        $draft ??= $this->draft();

        return $this->generation->start($this->owner, $draft->uuid, 1, $key ?? (string) Str::uuid(), $source);
    }

    private function pdf(): QuotationPdf
    {
        return new QuotationPdf('%PDF-fixture', 1, hash('sha256', '%PDF-fixture'));
    }

    private function subscription(): Subscription
    {
        return Subscription::create(['user_id' => $this->owner->id, 'provider_customer_id' => 'cus_fixture', 'provider_price_id' => 'price_pro',
            'provider_subscription_id' => 'sub_fixture', 'status' => 'active', 'current_period_start' => now()->subDay(), 'current_period_end' => now()->addDays(29)]);
    }

    public function test_signed_events_same_purchase_different_ids_and_repeated_event_grant_once(): void
    {
        $order = $this->order();
        foreach (['evt_same', 'evt_same', 'evt_distinct'] as $id) {
            $this->signed('checkout.session.completed', $this->checkoutSession($order), $id)->assertOk();
        }
        $this->signed('checkout.session.async_payment_succeeded', $this->checkoutSession($order))->assertOk();
        $this->assertDatabaseCount('quotation_credit_grants', 1);
        $this->assertDatabaseHas('quotation_credit_grants', ['order_id' => $order->id, 'granted' => 5, 'reserved' => 0]);
        $this->assertDatabaseCount('purchases', 0);
        $this->getJson('/api/quotations/entitlements')->assertOk()->assertJsonPath('data.credits.available', 5);
    }

    public function test_unsigned_or_mismatched_events_never_grant_or_permanently_deduplicate(): void
    {
        $this->postJson('/api/stripe/webhook', [])->assertStatus(400);
        $order = $this->order();
        foreach ([['amount_total' => 1], ['currency' => 'usd'], ['id' => 'cs_test_other'], ['client_reference_id' => 'foreign'], ['livemode' => true]] as $overrides) {
            $this->assertContains($this->signed('checkout.session.completed', $this->checkoutSession($order, $overrides))->status(), [400, 422]);
        }
        $this->assertDatabaseCount('quotation_credit_grants', 0);
        $this->assertDatabaseCount('stripe_webhook_events', 0);
    }

    public function test_unpaid_pending_failed_expired_and_delayed_confirmation(): void
    {
        $order = $this->order();
        $this->signed('checkout.session.completed', $this->checkoutSession($order, ['payment_status' => 'unpaid']))->assertOk();
        $this->assertDatabaseCount('quotation_credit_grants', 0);
        $this->signed('checkout.session.async_payment_failed', $this->checkoutSession($order, ['payment_status' => 'unpaid']))->assertOk();
        $this->assertSame('failed', $order->fresh()->status);
        $this->signed('checkout.session.async_payment_succeeded', $this->checkoutSession($order))->assertOk();
        $this->assertSame('paid', $order->fresh()->status);
        $this->signed('checkout.session.expired', $this->checkoutSession($order, ['status' => 'expired', 'payment_status' => 'unpaid']))->assertOk();
        $this->assertSame('paid', $order->fresh()->status);
        $other = $this->order();
        $this->signed('checkout.session.expired', $this->checkoutSession($other, ['status' => 'expired', 'payment_status' => 'unpaid']))->assertOk();
        $this->assertSame('cancelled', $other->fresh()->status);
    }

    public function test_checkout_same_key_and_ambiguous_network_failure_preserve_one_order(): void
    {
        $gateway = Mockery::mock(QuotationStripeGateway::class);
        $gateway->shouldReceive('available')->andReturnTrue();
        $calls = 0;
        $gateway->shouldReceive('checkout')->twice()->andReturnUsing(function ($order) use (&$calls) {
            if (++$calls === 1) {
                throw new \RuntimeException('Simulated network timeout');
            }

            return $this->checkoutSession($order, ['id' => 'cs_test_retry', 'status' => 'open', 'payment_status' => 'unpaid', 'url' => 'https://checkout.stripe.com/c/pay/fixture']);
        });
        $service = new QuotationOrderService($gateway);
        $key = (string) Str::uuid();
        try {
            $service->checkout($this->owner, $key);
            $this->fail('Expected timeout');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated network timeout', $e->getMessage());
        }
        $this->assertDatabaseCount('quotation_credit_orders', 1);
        $order = $service->checkout($this->owner, $key);
        $this->assertSame($order->id, $service->checkout($this->owner, $key)->id);
        $this->assertSame('cs_test_retry', $order->session_id);
        $this->assertDatabaseCount('quotation_credit_grants', 0);
    }

    public function test_status_retrieves_provider_and_url_parameters_do_not_confirm_payment(): void
    {
        $order = $this->order();
        $gateway = Mockery::mock(QuotationStripeGateway::class);
        $gateway->shouldReceive('session')->with($order->session_id)->once()->andReturn($this->checkoutSession($order));
        $this->app->instance(QuotationStripeGateway::class, $gateway);
        $this->getJson('/api/quotations/credit-orders/'.$order->uuid.'?payment=success&credits=999')->assertOk()->assertJsonPath('data.status', 'paid')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseHas('quotation_credit_grants', ['granted' => 5]);
        $this->assertDatabaseCount('quotation_generations', 0);
    }

    public function test_credit_is_explicit_free_preserves_it_and_idempotent_replay_keeps_origin(): void
    {
        $this->grant();
        $draft = $this->draft();
        $key = (string) Str::uuid();
        $processing = $this->generation->start($this->owner, $draft->uuid, 1, $key);
        $this->assertSame('free', QuotationGeneration::first()->source);
        $this->generation->complete($this->generation->claim($processing->uuid), $this->pdf());
        $this->assertDatabaseHas('quotation_credit_grants', ['consumed' => 0, 'reserved' => 0]);
        $creditDraft = $this->draft();
        $creditKey = (string) Str::uuid();
        $credit = $this->start('credit', $creditDraft, $creditKey);
        $claim = $this->generation->claim($credit->uuid);
        $this->assertTrue($this->generation->complete($claim, $this->pdf()));
        $this->assertFalse($this->generation->complete($claim, $this->pdf()));
        $this->assertSame($credit->uuid, $this->start('credit', $creditDraft, $creditKey)->uuid);
        $this->generation->purge($credit);
        $this->assertDatabaseHas('quotation_credit_grants', ['consumed' => 1, 'reserved' => 0]);
        $this->assertDatabaseHas('quotation_daily_quotas', ['consumed' => 1]);
    }

    public function test_terminal_failure_retry_and_exhaustion_preserve_the_credit_grant(): void
    {
        $this->grant(1);
        $processing = $this->start();
        $claim = $this->generation->claim($processing->uuid);
        $this->assertTrue($this->generation->failAttempt($claim, 'render_failed'));
        $this->assertDatabaseHas('quotation_credit_grants', ['reserved' => 1, 'consumed' => 0]);
        $this->assertFalse($this->generation->failAttempt($claim, 'render_failed'));
        $claim = $this->generation->claim($processing->uuid);
        config(['quotations.max_attempts' => 1]);
        $this->assertFalse($this->generation->failAttempt($claim, 'render_failed'));
        $this->assertDatabaseHas('quotation_credit_grants', ['reserved' => 0, 'consumed' => 0]);
        $this->generation->retry($this->owner, $processing->uuid, $claim->attempt, (string) Str::uuid());
        $this->generation->complete($this->generation->claim($processing->uuid), $this->pdf());
        $this->expectExceptionObject(new QuotationGenerationException('credits_exhausted', 422));
        $this->start();
    }

    public function test_refund_before_finalize_fences_output_and_revokes_only_unused_credits(): void
    {
        $order = $this->grant();
        $processing = $this->start();
        $claim = $this->generation->claim($processing->uuid);
        $refund = (object) ['id' => 'ch_fixture', 'livemode' => false, 'payment_intent' => $order->payment_intent_id,
            'currency' => 'brl', 'amount' => 990, 'amount_refunded' => 990];
        $this->signed('charge.refunded', $refund)->assertOk();
        $this->assertFalse($this->generation->complete($claim, $this->pdf()));
        $this->assertDatabaseHas('quotation_credit_grants', ['reserved' => 0, 'consumed' => 0, 'revoked' => 5]);
        $this->signed('checkout.session.completed', $this->checkoutSession($order))->assertOk();
        $this->assertSame('refunded', $order->fresh()->status);
        $this->assertDatabaseCount('quotation_credit_grants', 1);
        $this->assertSame('credits_revoked', QuotationGeneration::first()->error_code);
    }

    public function test_refund_after_consumption_partial_refund_and_dispute_never_make_balance_negative(): void
    {
        $order = $this->grant();
        $processing = $this->start();
        $this->generation->complete($this->generation->claim($processing->uuid), $this->pdf());
        $this->signed('charge.refunded', (object) ['id' => 'ch_fixture', 'livemode' => false, 'payment_intent' => $order->payment_intent_id,
            'currency' => 'brl', 'amount' => 990, 'amount_refunded' => 100])->assertOk();
        $this->assertDatabaseHas('quotation_credit_grants', ['consumed' => 1, 'revoked' => 4, 'reserved' => 0]);
        $this->signed('charge.dispute.created', (object) ['id' => 'dp_fixture', 'livemode' => false, 'payment_intent' => $order->payment_intent_id,
            'currency' => 'brl', 'amount' => 990])->assertOk();
        $this->getJson('/api/quotations/entitlements')->assertJsonPath('data.credits.available', 0);
        $this->assertDatabaseHas('quotation_credit_orders', ['refunded_amount' => 100, 'status' => 'refunded']);
    }

    public function test_refund_before_confirmation_routes_before_dedup_and_never_grants_later(): void
    {
        $order = $this->order();
        $gateway = Mockery::mock(QuotationStripeGateway::class);
        $gateway->shouldReceive('available')->andReturnTrue();
        $gateway->shouldReceive('orderForIntent')->with('pi_early')->andReturn($order->uuid);
        $this->app->instance(QuotationStripeGateway::class, $gateway);
        $this->signed('charge.refunded', (object) ['id' => 'ch_early', 'livemode' => false, 'payment_intent' => 'pi_early',
            'currency' => 'brl', 'amount' => 990, 'amount_refunded' => 990])->assertOk();
        $this->signed('checkout.session.completed', $this->checkoutSession($order, ['payment_intent' => 'pi_early']))->assertOk();
        $this->assertDatabaseCount('quotation_credit_grants', 0);
        $this->assertSame('refunded', $order->fresh()->status);
    }

    public function test_pro_first_exhaustion_explicit_credit_and_no_second_subscription(): void
    {
        $this->subscription();
        $this->grant();
        $this->getJson('/api/quotations/entitlements')->assertJsonPath('data.pro.remaining', 100)->assertJsonPath('data.source', 'pro');
        $draft = $this->draft();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/quotations/drafts/'.$draft->uuid.'/generations', ['revision' => 1, 'source' => 'credit'])->assertUnprocessable()->assertJsonPath('code', 'pro_first');
        $processing = $this->start('pro', $draft);
        $this->generation->complete($this->generation->claim($processing->uuid), $this->pdf());
        $this->assertDatabaseHas('quotation_pro_buckets', ['consumed' => 1, 'reserved' => 0]);
        DB::table('quotation_pro_buckets')->update(['consumed' => 100]);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/quotations/drafts/'.$this->draft()->uuid.'/generations', ['revision' => 1, 'source' => 'pro'])->assertUnprocessable()->assertJsonPath('code', 'pro_quota_exhausted');
        $credit = $this->start('credit');
        $this->generation->complete($this->generation->claim($credit->uuid), $this->pdf());
        $this->assertDatabaseHas('quotation_credit_grants', ['consumed' => 1]);
        $this->assertDatabaseCount('subscriptions', 1);
    }

    public function test_reserved_pro_finishes_in_original_period_after_renewal_and_cancellation(): void
    {
        $sub = $this->subscription();
        $periodEnd = now()->addMinute();
        $sub->update(['current_period_end' => $periodEnd]);
        $processing = $this->start('pro');
        $claim = $this->generation->claim($processing->uuid);
        $old = $claim->pro_bucket_id;
        $this->travel(61)->seconds();
        $sub->update(['current_period_start' => $periodEnd, 'current_period_end' => $periodEnd->copy()->addMonth()]);
        $this->getJson('/api/quotations/entitlements')->assertJsonPath('data.pro.remaining', 100);
        $sub->update(['status' => 'canceled']);
        $this->assertTrue($this->generation->complete($claim, $this->pdf()));
        $this->assertDatabaseHas('quotation_pro_buckets', ['id' => $old, 'consumed' => 1, 'reserved' => 0]);
        $this->getJson('/api/quotations/entitlements')->assertJsonPath('data.pro.active', false)->assertJsonPath('data.remaining', 1);
        $this->assertSame(0, (int) DB::table('quotation_pro_buckets')->where('id', '!=', $old)->value('consumed'));
    }

    public function test_invalid_pro_periods_do_not_grant_benefit_and_retry_never_moves_period(): void
    {
        $sub = $this->subscription();
        $processing = $this->start('pro');
        $claim = $this->generation->claim($processing->uuid);
        config(['quotations.max_attempts' => 1]);
        $this->generation->failAttempt($claim, 'render_failed');
        $sub->update(['current_period_start' => now(), 'current_period_end' => now()->addMonth()]);
        try {
            $this->generation->retry($this->owner, $processing->uuid, 1, (string) Str::uuid());
            $this->fail('Expected old-period rejection');
        } catch (QuotationGenerationException $e) {
            $this->assertSame('pro_period_invalid', $e->reason);
        }
        foreach ([['status' => 'past_due'], ['status' => 'active', 'current_period_start' => now()->addDay()],
            ['current_period_start' => now()->subMonth(), 'current_period_end' => now()], ['current_period_start' => null, 'current_period_end' => now()->addMonth()]] as $values) {
            $sub->update($values);
            $this->getJson('/api/quotations/entitlements')->assertJsonPath('data.pro.active', false);
        }
    }

    public function test_order_ownership_csrf_strict_inputs_and_live_are_rejected(): void
    {
        $order = $this->order();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/quotations/credit-orders', ['amount' => 1])->assertUnprocessable();
        Auth::forgetGuards();
        $this->actingAs(User::factory()->create(), 'web');
        $this->getJson('/api/quotations/credit-orders/'.$order->uuid)->assertNotFound();
        $this->getJson('/api/quotations/credit-orders')->assertOk()->assertJsonCount(0, 'data');
        $this->app['env'] = 'local';
        $this->postJson('/api/quotations/credit-orders')->assertStatus(419);
        $this->app['env'] = 'testing';
        config(['monetization.stripe.mode' => 'live', 'monetization.stripe.secret_key' => 'sk_live_fixture']);
        $this->assertFalse(app(QuotationStripeGateway::class)->available());
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/quotations/credit-orders')->assertStatus(503);
        Auth::guard('web')->logout();
        Auth::forgetGuards();
        $this->getJson('/api/quotations/credit-orders')->assertUnauthorized();
        $this->getJson('/api/quotations/entitlements')->assertUnauthorized();
    }
}
