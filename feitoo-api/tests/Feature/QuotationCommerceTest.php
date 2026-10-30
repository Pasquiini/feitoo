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
        config(['quotations.enabled' => true, 'quotations.purchases_enabled' => true, 'quotations.sandbox' => 'php', 'quotations.credit_price_id' => 'price_test_fixture',
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
            'currency' => 'brl', 'stripe_mode' => config('monetization.stripe.mode'), 'price_id' => 'price_test_fixture', 'expires_at' => now()->addHour(),
            'session_id' => 'cs_'.config('monetization.stripe.mode').'_'.Str::random(20)]);
    }

    private function checkoutSession(QuotationCreditOrder $order, array $overrides = []): Session
    {
        return Session::constructFrom(array_replace(['id' => $order->session_id, 'object' => 'checkout.session', 'livemode' => $order->stripe_mode === 'live',
            'mode' => 'payment', 'status' => 'complete', 'client_reference_id' => $order->uuid, 'amount_total' => 990, 'currency' => 'brl',
            'payment_status' => 'paid', 'payment_intent' => 'pi_'.$order->uuid,
            'line_items' => ['data' => [['quantity' => 1, 'price' => ['id' => $order->price_id, 'unit_amount' => 990, 'currency' => 'brl', 'livemode' => $order->stripe_mode === 'live']]], 'has_more' => false],
            'metadata' => ['quotation_order' => $order->uuid, 'operation' => 'quotation_credits']], $overrides));
    }

    private function signed(string $type, object $object, ?string $id = null)
    {
        $payload = json_encode(['id' => $id ?? 'evt_'.Str::random(20), 'object' => 'event', 'type' => $type, 'livemode' => config('monetization.stripe.mode') === 'live',
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

    public function test_disabling_new_purchases_keeps_existing_order_confirmation_and_credits(): void
    {
        $order = $this->order();
        config(['quotations.purchases_enabled' => false]);
        $this->signed('checkout.session.completed', $this->checkoutSession($order))->assertOk();
        $this->getJson('/api/quotations/entitlements')->assertOk()
            ->assertJsonPath('data.offer.available', false)->assertJsonPath('data.credits.available', 5);
        $this->assertSame('paid', $order->fresh()->status);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/quotations/credit-orders', [])->assertStatus(503);
        $this->assertDatabaseCount('quotation_credit_orders', 1);
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

    public function test_live_signed_payment_refund_and_test_balances_remain_separate(): void
    {
        $testOrder = $this->grant();
        config(['monetization.stripe.mode' => 'live', 'monetization.stripe.secret_key' => 'sk_live_fixture']);
        $order = $this->order();
        foreach (['evt_live_once', 'evt_live_once', 'evt_live_distinct'] as $event) {
            $this->signed('checkout.session.completed', $this->checkoutSession($order), $event)->assertOk();
        }
        $this->getJson('/api/quotations/entitlements')->assertJsonPath('data.credits.available', 5);
        $this->getJson('/api/quotations/credit-orders')->assertJsonCount(1, 'data')->assertJsonPath('data.0.stripe_mode', 'live');
        $this->getJson('/api/quotations/credit-orders/'.$testOrder->uuid)->assertNotFound();
        $this->signed('checkout.session.completed', $this->checkoutSession($testOrder))->assertStatus(400);
        $processing = $this->start('credit');
        $claim = $this->generation->claim($processing->uuid);
        $this->assertSame($order->id, (int) DB::table('quotation_credit_grants')->where('id', $claim->credit_grant_id)->value('order_id'));
        $this->generation->complete($claim, $this->pdf());
        $this->signed('charge.refunded', (object) ['id' => 'ch_live_fixture', 'livemode' => true, 'payment_intent' => 'pi_'.$order->uuid,
            'currency' => 'brl', 'amount' => 990, 'amount_refunded' => 990])->assertOk();
        $this->getJson('/api/quotations/entitlements')->assertJsonPath('data.credits.available', 0);
        $this->assertDatabaseHas('quotation_credit_grants', ['order_id' => $order->id, 'consumed' => 1, 'revoked' => 4]);
        $this->assertDatabaseHas('quotation_credit_grants', ['order_id' => $testOrder->id, 'consumed' => 0, 'revoked' => 0]);
        $this->assertDatabaseCount('quotation_credit_grants', 2);
        $this->assertDatabaseCount('purchases', 0);
    }

    public function test_signed_webhook_recovers_a_session_created_before_a_lost_checkout_response(): void
    {
        $order = $this->order();
        $session = $this->checkoutSession($order);
        $order->update(['session_id' => null]);
        $this->signed('checkout.session.completed', $session, 'evt_recovery')->assertOk();
        $this->signed('checkout.session.completed', $session, 'evt_recovery')->assertOk();
        $this->assertSame($session->id, $order->fresh()->session_id);
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertDatabaseCount('quotation_credit_grants', 1);
    }

    public function test_expired_ambiguous_checkout_never_creates_a_new_remote_charge(): void
    {
        $order = $this->order();
        $order->update(['session_id' => null, 'expires_at' => now()->subDay()]);
        $gateway = Mockery::mock(QuotationStripeGateway::class);
        $gateway->shouldReceive('available')->once()->andReturnTrue();
        $gateway->shouldNotReceive('checkout');
        $gateway->shouldReceive('findSession')->once()->andReturnNull();
        $result = (new QuotationOrderService($gateway))->checkout($this->owner, $order->idempotency_key);
        $this->assertSame('cancelled', $result->status);
        $this->assertDatabaseCount('quotation_credit_grants', 0);
    }

    public function test_expired_ambiguous_checkout_recovers_original_payment_without_recreating_a_session(): void
    {
        $order = $this->order();
        $session = $this->checkoutSession($order);
        $order->update(['session_id' => null, 'expires_at' => now()->subMinute()]);
        $gateway = Mockery::mock(QuotationStripeGateway::class);
        $gateway->shouldReceive('available')->once()->andReturnTrue();
        $gateway->shouldNotReceive('checkout');
        $gateway->shouldReceive('findSession')->once()->andReturn($session);
        $result = (new QuotationOrderService($gateway))->checkout($this->owner, $order->idempotency_key);
        $this->assertSame('paid', $result->status);
        $this->assertSame($session->id, $result->session_id);
        $this->assertDatabaseCount('quotation_credit_orders', 1);
        $this->assertDatabaseCount('quotation_credit_grants', 1);
    }

    public function test_won_dispute_restores_only_unused_units_and_stale_events_do_not_reblock(): void
    {
        $order = $this->grant();
        $processing = $this->start();
        $this->generation->complete($this->generation->claim($processing->uuid), $this->pdf());
        $base = ['id' => 'dp_fixture', 'livemode' => false, 'payment_intent' => $order->payment_intent_id, 'currency' => 'brl', 'amount' => 990];
        $pending = (object) [...$base, 'status' => 'needs_response'];
        $won = (object) [...$base, 'status' => 'won'];
        $gateway = Mockery::mock(QuotationStripeGateway::class);
        $gateway->shouldReceive('dispute')->with('dp_fixture')->times(4)->andReturn($pending, $won, $won, $won);
        $gateway->shouldReceive('available')->andReturnFalse();
        $this->app->instance(QuotationStripeGateway::class, $gateway);
        $this->signed('charge.dispute.created', $pending)->assertOk();
        $this->assertDatabaseHas('quotation_credit_grants', ['consumed' => 1, 'revoked' => 4]);
        $this->signed('charge.dispute.closed', $won, 'evt_won')->assertOk();
        $this->signed('charge.dispute.closed', $won, 'evt_won')->assertOk();
        $this->signed('charge.dispute.created', $pending)->assertOk();
        $this->getJson('/api/quotations/entitlements')->assertJsonPath('data.credits.available', 4);
        $this->assertDatabaseHas('quotation_credit_grants', ['consumed' => 1, 'revoked' => 0, 'blocked_at' => null]);
        $this->signed('charge.refunded', (object) ['id' => 'ch_fixture', 'livemode' => false, 'payment_intent' => $order->payment_intent_id,
            'currency' => 'brl', 'amount' => 990, 'amount_refunded' => 100])->assertOk();
        $this->signed('charge.dispute.closed', $won)->assertOk();
        $this->getJson('/api/quotations/entitlements')->assertJsonPath('data.credits.available', 0);
        $this->assertSame('refunded', $order->fresh()->status);
        $this->assertDatabaseCount('quotation_credit_grants', 1);
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
        $gateway = Mockery::mock(QuotationStripeGateway::class);
        $gateway->shouldReceive('available')->andReturnFalse();
        $gateway->shouldReceive('dispute')->andReturn((object) ['id' => 'dp_fixture', 'livemode' => false, 'status' => 'needs_response',
            'payment_intent' => $order->payment_intent_id, 'currency' => 'brl', 'amount' => 990]);
        $this->app->instance(QuotationStripeGateway::class, $gateway);
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

    public function test_order_ownership_csrf_strict_inputs_and_missing_live_price_are_rejected(): void
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
        config(['monetization.stripe.mode' => 'live', 'monetization.stripe.secret_key' => 'sk_live_fixture', 'quotations.credit_price_id' => null]);
        $this->assertFalse(app(QuotationStripeGateway::class)->available());
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/quotations/credit-orders')->assertStatus(503);
        Auth::guard('web')->logout();
        Auth::forgetGuards();
        $this->getJson('/api/quotations/credit-orders')->assertUnauthorized();
        $this->getJson('/api/quotations/entitlements')->assertUnauthorized();
    }

    public function test_direct_account_purchase_is_available_before_free_or_pro_quota_is_exhausted(): void
    {
        foreach ([false, true] as $pro) {
            if ($pro) {
                Subscription::create(['user_id' => $this->owner->id, 'provider_customer_id' => 'cus_fixture',
                    'provider_price_id' => 'price_pro', 'provider_subscription_id' => 'sub_fixture',
                    'status' => 'active', 'current_period_start' => now(), 'current_period_end' => now()->addMonth()]);
            }
            $gateway = Mockery::mock(QuotationStripeGateway::class)->makePartial();
            $gateway->shouldReceive('checkout')->once()->andReturnUsing(function ($order) {
                $this->assertSame('account', $order->return_destination);

                return $this->checkoutSession($order, ['id' => 'cs_test_'.$order->uuid, 'status' => 'open', 'payment_status' => 'unpaid',
                    'payment_intent' => null, 'url' => 'https://checkout.stripe.com/c/pay/fixture']);
            });
            $this->app->instance(QuotationStripeGateway::class, $gateway);
            $this->app->forgetInstance(QuotationOrderService::class);
            $key = (string) Str::uuid();
            $response = $this->withHeader('Idempotency-Key', $key)->postJson('/api/quotations/credit-orders', ['return_destination' => 'account'])
                ->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.actions.resume_checkout', true);
            // A replay cannot change the original return payload or create another Session.
            $this->withHeader('Idempotency-Key', $key)->postJson('/api/quotations/credit-orders', ['return_destination' => 'editor'])
                ->assertCreated()->assertJsonPath('data.id', $response->json('data.id'));
            $this->assertDatabaseCount('quotation_credit_grants', 0);
        }
        $this->assertDatabaseCount('quotation_credit_orders', 2);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/quotations/credit-orders', ['return_destination' => 'https://evil.test'])->assertUnprocessable();
        $this->postJson('/api/quotations/credit-orders', ['return_destination' => 'account', 'amount' => 1])->assertUnprocessable();
    }

    public function test_resume_actions_require_pending_unexpired_checkout_and_remote_open_session(): void
    {
        $order = $this->order();
        $order->update(['checkout_url' => 'https://checkout.stripe.com/c/pay/fixture']);
        $this->getJson('/api/quotations/credit-orders')->assertJsonPath('data.0.actions.resume_checkout', true);
        $gateway = Mockery::mock(QuotationStripeGateway::class)->makePartial();
        $gateway->shouldReceive('session')->once()->andReturn($this->checkoutSession($order, ['status' => 'complete', 'payment_status' => 'unpaid', 'payment_intent' => null]));
        $this->app->instance(QuotationStripeGateway::class, $gateway);
        $this->getJson('/api/quotations/credit-orders/'.$order->uuid.'?payment=success')->assertOk()
            ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.checkout_url', null)->assertJsonPath('data.actions.resume_checkout', false);
        $this->assertDatabaseCount('quotation_credit_grants', 0);
        $order->update(['expires_at' => now()->subSecond(), 'checkout_url' => 'https://checkout.stripe.com/old']);
        $this->getJson('/api/quotations/credit-orders')->assertJsonPath('data.0.checkout_url', null)->assertJsonPath('data.0.actions.resume_checkout', false);
    }

    public function test_direct_purchase_requires_csrf_and_does_not_create_attempt_when_session_is_expired(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/quotations/credit-orders', ['return_destination' => 'account'])->assertStatus(419);
        $this->assertDatabaseCount('quotation_credit_orders', 0);
        $this->app->detectEnvironment(fn () => 'testing');
        Auth::forgetGuards();
        $this->app['session']->flush();
        $this->postJson('/api/quotations/credit-orders', ['return_destination' => 'account'])->assertUnauthorized();
        $this->assertDatabaseCount('quotation_credit_orders', 0);
    }
}
