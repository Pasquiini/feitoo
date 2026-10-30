<?php

namespace Tests\Feature;

use App\Enums\PurchaseStatus;
use App\Models\Processing;
use App\Models\Purchase;
use App\Models\QuotationCreditOrder;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PurchaseService;
use App\Services\Quotations\QuotationOrderService;
use App\Services\Quotations\QuotationStripeGateway;
use App\Services\StripeCheckoutGateway;
use App\Services\StripeSubscriptionGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Stripe\ApiRequestor;
use Stripe\Checkout\Session;
use Stripe\HttpClient\ClientInterface;
use Stripe\PaymentIntent;
use Tests\TestCase;

class StripeDynamicPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::getDriverName());
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->freezeSecond();
        Storage::fake('processing');
        Queue::fake();
        $this->owner = User::factory()->create();
        $this->actingAs($this->owner);
        config(['quotations.enabled' => true, 'quotations.purchases_enabled' => true,
            'quotations.credit_price_id' => 'price_credits', 'monetization.pro.price_id' => 'price_pro']);
    }

    private function send(string $type, object $object, string $id)
    {
        $body = json_encode(['object' => 'event', 'id' => $id, 'livemode' => false, 'type' => $type, 'data' => ['object' => $object]]);
        $time = time();
        $signature = hash_hmac('sha256', $time.'.'.$body, 'whsec_test');

        return $this->call('POST', '/api/stripe/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => 't='.$time.',v1='.$signature], $body);
    }

    private function order(): QuotationCreditOrder
    {
        return QuotationCreditOrder::create(['user_id' => $this->owner->id, 'uuid' => (string) Str::uuid(),
            'idempotency_key' => (string) Str::uuid(), 'stripe_mode' => 'test', 'package_code' => 'quotation_5',
            'amount' => 990, 'credits' => 5, 'currency' => 'brl', 'price_id' => 'price_credits',
            'session_id' => 'cs_test_credits', 'expires_at' => now()->addHour()]);
    }

    private function creditSession(QuotationCreditOrder $order, bool $paid): Session
    {
        return Session::constructFrom(['object' => 'checkout.session', 'id' => $order->session_id, 'livemode' => false,
            'mode' => 'payment', 'status' => 'complete', 'payment_status' => $paid ? 'paid' : 'unpaid',
            'payment_intent' => 'pi_credits', 'client_reference_id' => $order->uuid, 'amount_total' => 990, 'currency' => 'brl',
            'metadata' => ['operation' => 'quotation_credits', 'quotation_order' => $order->uuid],
            'line_items' => ['data' => [['quantity' => 1, 'price' => ['id' => 'price_credits', 'livemode' => false,
                'unit_amount' => 990, 'currency' => 'brl']]], 'has_more' => false]]);
    }

    public static function delayedMethods(): array
    {
        return ['PIX' => ['pix'], 'boleto' => ['boleto']];
    }

    #[DataProvider('delayedMethods')]
    public function test_credit_pending_return_failure_recovery_and_reordered_events_grant_exactly_once(string $method): void
    {
        $order = $this->order();
        $pending = $this->creditSession($order, false);
        $pending->payment_method_types = [$method]; // Response field remains supported; never sent on creation.
        $this->send('checkout.session.completed', $pending, 'evt_pending')->assertOk();
        $gateway = Mockery::mock(QuotationStripeGateway::class)->makePartial();
        $gateway->shouldReceive('session')->once()->andReturn($pending);
        $this->app->instance(QuotationStripeGateway::class, $gateway);
        $this->app->forgetInstance(QuotationOrderService::class);
        $this->getJson('/api/quotations/credit-orders/'.$order->uuid.'?payment=success')->assertOk()->assertJsonPath('data.status', 'pending');
        $this->assertDatabaseCount('quotation_credit_grants', 0);
        $this->send('checkout.session.async_payment_failed', $pending, 'evt_failure')->assertOk();
        $this->assertSame('failed', $order->fresh()->status);
        $this->assertDatabaseCount('quotation_credit_grants', 0);
        $paid = $this->creditSession($order, true);
        foreach (['evt_paid', 'evt_paid', 'evt_paid_again'] as $id) {
            $this->send('checkout.session.async_payment_succeeded', $paid, $id)->assertOk();
        }
        $paidAt = $order->fresh()->paid_at;
        $this->send('checkout.session.completed', $pending, 'evt_stale')->assertOk();
        $this->send('checkout.session.async_payment_failed', $pending, 'evt_stale_failure')->assertOk();
        $intent = (object) ['id' => 'pi_credits', 'livemode' => false, 'amount' => 990, 'currency' => 'brl', 'metadata' => $paid->metadata];
        $this->send('payment_intent.succeeded', $intent, 'evt_intent_success')->assertOk();
        $this->send('payment_intent.payment_failed', $intent, 'evt_intent_failure')->assertOk();
        $this->assertDatabaseCount('quotation_credit_grants', 1);
        $this->assertDatabaseHas('quotation_credit_grants', ['granted' => 5, 'order_id' => $order->id]);
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertEquals($paidAt, $order->fresh()->paid_at);
    }

    public function test_paid_credit_webhook_without_line_items_reads_and_validates_the_remote_price(): void
    {
        $order = $this->order();
        $session = $this->creditSession($order, true);
        unset($session->line_items);
        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')->once()->withArgs(fn ($method, $url) => $method === 'get'
            && str_ends_with($url, '/checkout/sessions/cs_test_credits/line_items'))
            ->andReturn([json_encode(['object' => 'list', 'has_more' => false, 'data' => [['quantity' => 1,
                'price' => ['id' => 'price_foreign', 'unit_amount' => 990, 'currency' => 'brl', 'livemode' => false]]]]), 200, []]);
        ApiRequestor::setHttpClient($http);
        $this->send('checkout.session.async_payment_succeeded', $session, 'evt_bad_price')->assertStatus(422);
        $this->assertDatabaseCount('quotation_credit_grants', 0);
        $this->assertDatabaseCount('stripe_webhook_events', 0);
    }

    private function fiscalPurchase(): Purchase
    {
        $id = $this->postJson('/api/processings', ['tool' => 'xml-to-excel'])->assertCreated()->json('data.id');
        $this->postJson('/api/processings/'.$id.'/files', ['files' => [
            UploadedFile::fake()->createWithContent('test.xml', '<document/>'),
        ]])->assertOk();
        $processing = Processing::where('uuid', $id)->firstOrFail();

        return Purchase::create(['user_id' => $this->owner->id, 'processing_id' => $processing->id, 'package_code' => 'xml_100',
            'amount' => 490, 'currency' => 'brl', 'files_limit' => 100, 'files_count' => 1, 'expires_at' => now()->addMinutes(35),
            'provider_checkout_session_id' => 'cs_test_fiscal', 'checkout_url' => 'https://checkout.stripe.com/fiscal']);
    }

    private function fiscalSession(Purchase $purchase, bool $paid): Session
    {
        return Session::constructFrom(['id' => 'cs_test_fiscal', 'livemode' => false, 'object' => 'checkout.session',
            'mode' => 'payment', 'status' => 'complete', 'payment_status' => $paid ? 'paid' : 'unpaid', 'payment_intent' => 'pi_fiscal',
            'client_reference_id' => $purchase->uuid, 'amount_total' => 490, 'currency' => 'brl',
            'metadata' => ['purchase_uuid' => $purchase->uuid, 'processing_uuid' => $purchase->processing->uuid, 'package_code' => 'xml_100'],
            'line_items' => ['data' => [['quantity' => 1, 'price' => ['unit_amount' => 490, 'currency' => 'brl', 'livemode' => false]]], 'has_more' => false]]);
    }

    #[DataProvider('delayedMethods')]
    public function test_fiscal_pending_payment_survives_checkout_expiry_and_can_only_be_consumed_after_settlement(string $method): void
    {
        $purchase = $this->fiscalPurchase();
        $pending = $this->fiscalSession($purchase, false);
        $deadline = now()->addDays(3)->timestamp;
        $gateway = Mockery::mock(StripeCheckoutGateway::class)->makePartial();
        $gateway->shouldReceive('paymentIntent')->andReturn(PaymentIntent::constructFrom([
            'id' => 'pi_fiscal', 'livemode' => false, 'amount' => 490, 'currency' => 'brl', 'status' => 'processing',
            'metadata' => $pending->metadata, 'next_action' => [($method === 'pix' ? 'pix_display_qr_code' : 'boleto_display_details') => ['expires_at' => $deadline]]]));
        $gateway->shouldReceive('session')->andReturn($pending);
        $this->app->instance(StripeCheckoutGateway::class, $gateway);
        $originalExpiry = $purchase->expires_at;
        $this->send('checkout.session.completed', $pending, 'evt_fiscal_pending')->assertOk();
        $retainedUntil = $purchase->processing->fresh()->expires_at;
        $this->send('checkout.session.completed', $pending, 'evt_fiscal_duplicate')->assertOk();
        $this->assertEquals($retainedUntil, $purchase->processing->fresh()->expires_at);
        $this->assertEquals($originalExpiry, $purchase->fresh()->expires_at);
        $this->assertTrue($purchase->fresh()->awaitingPayment());
        $this->postJson('/api/processings/'.$purchase->processing->uuid.'/purchase/cancel')->assertUnprocessable()->assertJsonPath('code', 'payment_pending');
        $this->travel(8)->days();
        $this->artisan('feitoo:expire-pending-purchases')->assertSuccessful();
        $this->artisan('feitoo:purge-expired-processings')->assertSuccessful();
        $this->assertSame('pending', $purchase->processing->fresh()->status->value);
        $this->getJson('/api/processings/'.$purchase->processing->uuid.'/purchase?payment=success')->assertOk()->assertJsonPath('data.status', 'pending');
        $this->postJson('/api/processings/'.$purchase->processing->uuid.'/start')->assertUnprocessable()->assertJsonPath('code', 'payment_pending');
        $paid = $this->fiscalSession($purchase, true);
        $this->send('checkout.session.async_payment_succeeded', $paid, 'evt_fiscal_paid')->assertOk();
        $this->send('checkout.session.async_payment_succeeded', $paid, 'evt_fiscal_paid')->assertOk();
        $this->send('checkout.session.async_payment_failed', $pending, 'evt_fiscal_late_failure')->assertOk();
        $this->assertSame(PurchaseStatus::Paid, $purchase->fresh()->status);
        $this->postJson('/api/processings/'.$purchase->processing->uuid.'/start')->assertStatus(202);
        $this->postJson('/api/processings/'.$purchase->processing->uuid.'/start')->assertConflict();
        $this->assertNotNull($purchase->fresh()->consumed_at);
        $this->assertDatabaseCount('purchases', 1);
        $this->assertDatabaseCount('quotation_credit_grants', 0);
    }

    public function test_fiscal_sdk_checkout_uses_dynamic_methods_and_preserves_price_metadata_and_key(): void
    {
        $purchase = $this->fiscalPurchase();
        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')->once()->withArgs(function ($method, $url, $headers, $params) use ($purchase) {
            foreach (['payment_method_types', 'allowed_payment_method_types', 'excluded_payment_method_types', 'automatic_payment_methods'] as $key) {
                $this->assertArrayNotHasKey($key, $params);
            }
            $this->assertSame('payment', $params['mode']);
            $this->assertSame(490, $params['line_items'][0]['price_data']['unit_amount']);
            $this->assertSame('brl', $params['line_items'][0]['price_data']['currency']);
            $this->assertSame(1, $params['line_items'][0]['quantity']);
            $this->assertSame($purchase->uuid, $params['client_reference_id']);
            $this->assertSame($params['metadata'], $params['payment_intent_data']['metadata']);
            $this->assertStringContainsString('purchase-'.$purchase->uuid, implode(' ', $headers));

            return $method === 'post' && str_ends_with($url, '/v1/checkout/sessions');
        })->andReturn([json_encode(['id' => 'cs_test_fiscal', 'object' => 'checkout.session', 'livemode' => false,
            'url' => 'https://checkout.stripe.com/fiscal']), 200, []]);
        ApiRequestor::setHttpClient($http);
        $this->assertSame('cs_test_fiscal', app(StripeCheckoutGateway::class)->create($purchase, $purchase->processing)->id);
    }

    public static function divergentCreditSessions(): array
    {
        return ['wrong price' => [['line_items' => ['data' => [['price' => ['id' => 'price_foreign']]]]]],
            'wrong quantity' => [['line_items' => ['data' => [['quantity' => 2]]]]],
            'wrong currency' => [['currency' => 'usd']], 'wrong amount' => [['amount_total' => 1]],
            'wrong operation' => [['metadata' => ['operation' => 'another_operation']]],
            'wrong price environment' => [['line_items' => ['data' => [['price' => ['livemode' => true]]]]]]];
    }

    #[DataProvider('divergentCreditSessions')]
    public function test_divergent_async_confirmation_rolls_back_financial_state_and_deduplication(array $overrides): void
    {
        $order = $this->order();
        $session = Session::constructFrom(array_replace_recursive($this->creditSession($order, true)->toArray(), $overrides));
        $this->send('checkout.session.async_payment_succeeded', $session, 'evt_divergent')->assertStatus(422);
        $this->assertDatabaseCount('quotation_credit_grants', 0);
        $this->assertDatabaseCount('stripe_webhook_events', 0);
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_fiscal_async_failure_and_stale_completion_do_not_grant_and_later_payment_can_recover(): void
    {
        $purchase = $this->fiscalPurchase();
        $pending = $this->fiscalSession($purchase, false);
        $this->send('checkout.session.async_payment_failed', $pending, 'evt_fiscal_failed')->assertOk();
        $this->send('checkout.session.completed', $pending, 'evt_fiscal_stale')->assertOk();
        $this->assertSame(PurchaseStatus::Failed, $purchase->fresh()->status);
        $this->assertNull($purchase->fresh()->paid_at);
        $this->send('checkout.session.async_payment_succeeded', $this->fiscalSession($purchase, true), 'evt_fiscal_recovered')->assertOk();
        $this->assertSame(PurchaseStatus::Paid, $purchase->fresh()->status);
    }

    public function test_fiscal_signed_confirmation_recovers_a_lost_create_response_without_a_second_charge(): void
    {
        $purchase = $this->fiscalPurchase();
        $purchase->update(['provider_checkout_session_id' => null, 'checkout_url' => null]);
        $session = $this->fiscalSession($purchase, true);
        $this->send('checkout.session.async_payment_succeeded', $session, 'evt_lost_fiscal_response')->assertOk();
        $this->send('checkout.session.async_payment_succeeded', $session, 'evt_lost_fiscal_response')->assertOk();
        $this->assertSame('cs_test_fiscal', $purchase->fresh()->provider_checkout_session_id);
        $this->assertSame(PurchaseStatus::Paid, $purchase->fresh()->status);
        $this->assertDatabaseCount('purchases', 1);
    }

    public function test_fiscal_reconciliation_recovers_an_unregistered_session_with_get_only(): void
    {
        $purchase = $this->fiscalPurchase();
        $purchase->update(['provider_checkout_session_id' => null, 'checkout_url' => null]);
        $gateway = Mockery::mock(StripeCheckoutGateway::class);
        $gateway->shouldNotReceive('create');
        $gateway->shouldReceive('findPurchaseSession')->once()->andReturn($this->fiscalSession($purchase, true));
        $this->app->instance(StripeCheckoutGateway::class, $gateway);
        $this->getJson('/api/processings/'.$purchase->processing->uuid.'/purchase?payment=success')->assertOk()
            ->assertJsonPath('data.status', 'paid');
        $this->assertSame('cs_test_fiscal', $purchase->fresh()->provider_checkout_session_id);
    }

    public function test_fiscal_cleanup_reconciles_a_completed_session_before_a_delayed_webhook_and_preserves_files_on_network_failure(): void
    {
        $purchase = $this->fiscalPurchase();
        $processing = $purchase->processing;
        $file = $processing->files()->firstOrFail();
        $gateway = Mockery::mock(StripeCheckoutGateway::class);
        $gateway->shouldReceive('session')->once()->andThrow(new \RuntimeException('Simulated communication failure'));
        $gateway->shouldNotReceive('create');
        $this->app->instance(StripeCheckoutGateway::class, $gateway);
        $this->travel(3)->hours();
        $this->artisan('feitoo:purge-expired-processings')->assertFailed();
        Storage::disk($file->storage_disk)->assertExists($file->storage_path);
        $this->assertSame('pending', $processing->fresh()->status->value);
        $gateway = Mockery::mock(StripeCheckoutGateway::class);
        $gateway->shouldReceive('session')->once()->andReturn($this->fiscalSession($purchase, true));
        $this->app->instance(StripeCheckoutGateway::class, $gateway);
        $this->app->forgetInstance(PurchaseService::class);
        $this->artisan('feitoo:purge-expired-processings')->assertSuccessful();
        Storage::disk($file->storage_disk)->assertExists($file->storage_path);
        $this->assertSame(PurchaseStatus::Paid, $purchase->fresh()->status);
        $this->assertTrue($processing->fresh()->expires_at->isFuture());
    }

    public function test_pro_active_with_unpaid_or_void_invoice_never_grants_and_paid_renewal_uses_current_state(): void
    {
        $this->owner->forceFill(['stripe_customer_id' => 'cus_dynamic'])->save();
        $sub = Subscription::create(['user_id' => $this->owner->id, 'provider_customer_id' => 'cus_dynamic',
            'provider_price_id' => 'price_pro', 'contract_amount' => 2990, 'contract_currency' => 'brl']);
        $remote = ['id' => 'sub_dynamic', 'livemode' => false, 'customer' => 'cus_dynamic', 'status' => 'active',
            'collection_method' => 'charge_automatically', 'metadata' => ['feitoo_subscription' => (string) $sub->id],
            'items' => ['data' => [['quantity' => 1, 'price' => ['id' => 'price_pro', 'unit_amount' => 2990, 'currency' => 'brl'],
                'current_period_start' => now()->timestamp, 'current_period_end' => now()->addMonth()->timestamp]]],
            'latest_invoice' => ['id' => 'in_dynamic', 'object' => 'invoice', 'livemode' => false, 'status' => 'open',
                'customer' => 'cus_dynamic', 'currency' => 'brl', 'total' => 2990,
                'parent' => ['subscription_details' => ['subscription' => 'sub_dynamic']]],
            'cancel_at_period_end' => false, 'canceled_at' => null];
        // Closure must observe subsequent provider state, including stale deliveries after renewal.
        $gateway = Mockery::mock(StripeSubscriptionGateway::class)->makePartial();
        $gateway->shouldReceive('retrieve')->andReturnUsing(function () use (&$remote) {
            return \Stripe\Subscription::constructFrom($remote);
        });
        $this->app->instance(StripeSubscriptionGateway::class, $gateway);
        $event = (object) ['object' => 'checkout.session', 'id' => 'cs_test_pro', 'livemode' => false, 'mode' => 'subscription',
            'subscription' => 'sub_dynamic', 'customer' => 'cus_dynamic', 'payment_status' => 'unpaid'];
        foreach (['open', 'void'] as $status) {
            $remote['latest_invoice']['status'] = $status;
            $this->send('checkout.session.completed', $event, 'evt_pro_'.$status)->assertOk();
            $this->assertFalse($sub->fresh()->grantsPro());
        }
        $remote['latest_invoice']['status'] = 'paid';
        $invoice = (object) ['id' => 'in_dynamic', 'object' => 'invoice', 'livemode' => false, 'customer' => 'cus_dynamic',
            'parent' => ['subscription_details' => ['subscription' => 'sub_dynamic']]];
        $this->send('invoice.paid', $invoice, 'evt_initial_paid')->assertOk();
        $this->assertTrue($sub->fresh()->grantsPro());
        $remote['items']['data'][0]['current_period_end'] = now()->addMonths(2)->timestamp;
        $remote['latest_invoice']['status'] = 'open';
        $this->send('customer.subscription.updated', (object) ['id' => 'sub_dynamic', 'livemode' => false, 'customer' => 'cus_dynamic'], 'evt_renew_pending')->assertOk();
        $this->assertFalse($sub->fresh()->grantsPro());
        $remote['latest_invoice']['status'] = 'paid';
        $this->send('invoice.paid', $invoice, 'evt_renew_paid')->assertOk();
        $this->send('invoice.payment_failed', $invoice, 'evt_old_invoice_failure')->assertOk();
        $this->assertTrue($sub->fresh()->grantsPro());
        $this->assertSame(now()->addMonths(2)->timestamp, $sub->fresh()->current_period_end->timestamp);
        $this->assertDatabaseCount('subscriptions', 1);
    }

    public static function invalidProPayments(): array
    {
        return ['pending' => [['latest_invoice' => ['status' => 'open']], false],
            'void' => [['latest_invoice' => ['status' => 'void']], false],
            'manual collection' => [['collection_method' => 'send_invoice'], false],
            'wrong invoice total' => [['latest_invoice' => ['total' => 1]], true],
            'wrong invoice customer' => [['latest_invoice' => ['customer' => 'cus_foreign']], true],
            'wrong invoice subscription' => [['latest_invoice' => ['parent' => ['subscription_details' => ['subscription' => 'sub_foreign']]]], true],
            'wrong contract price amount' => [['items' => ['data' => [['price' => ['unit_amount' => 1]]]]], true]];
    }

    #[DataProvider('invalidProPayments')]
    public function test_invalid_or_pending_pro_payment_never_activates_or_rewrites_the_contract(array $overrides, bool $rejected): void
    {
        $this->owner->forceFill(['stripe_customer_id' => 'cus_pro'])->save();
        $sub = Subscription::create(['user_id' => $this->owner->id, 'provider_customer_id' => 'cus_pro',
            'provider_price_id' => 'price_pro', 'contract_amount' => 2990, 'contract_currency' => 'brl']);
        $remote = array_replace_recursive(['id' => 'sub_pro', 'livemode' => false, 'customer' => 'cus_pro', 'status' => 'active',
            'collection_method' => 'charge_automatically', 'metadata' => ['feitoo_subscription' => (string) $sub->id],
            'items' => ['data' => [['quantity' => 1, 'price' => ['id' => 'price_pro', 'unit_amount' => 2990, 'currency' => 'brl'],
                'current_period_end' => now()->addMonth()->timestamp]]],
            'latest_invoice' => ['object' => 'invoice', 'id' => 'in_pro', 'livemode' => false, 'status' => 'paid',
                'customer' => 'cus_pro', 'currency' => 'brl', 'total' => 2990,
                'parent' => ['subscription_details' => ['subscription' => 'sub_pro']]],
            'cancel_at_period_end' => false, 'canceled_at' => null], $overrides);
        $gateway = Mockery::mock(StripeSubscriptionGateway::class);
        $gateway->shouldReceive('retrieve')->once()->andReturn(\Stripe\Subscription::constructFrom($remote));
        $this->app->instance(StripeSubscriptionGateway::class, $gateway);
        $this->send('checkout.session.completed', (object) ['id' => 'cs_test_pro', 'livemode' => false, 'mode' => 'subscription',
            'subscription' => 'sub_pro', 'customer' => 'cus_pro'], 'evt_invalid_pro')->assertStatus($rejected ? 500 : 200);
        $this->assertFalse($sub->fresh()->grantsPro());
        $this->assertSame(2990, $sub->fresh()->contract_amount);
        if ($rejected) {
            $this->assertDatabaseCount('stripe_webhook_events', 0);
        }
    }
}
