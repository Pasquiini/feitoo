<?php

namespace Tests\Feature;

use App\Enums\ProcessingStatus;
use App\Enums\PurchaseStatus;
use App\Models\Processing;
use App\Models\Purchase;
use App\Models\User;
use App\Services\StripeCheckoutGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Stripe\Checkout\Session;
use Tests\TestCase;

class MonetizationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('processing');
        Queue::fake();
        $this->owner = User::factory()->create();
        $this->actingAs($this->owner, 'web');
        config(['feitoo.rate_limits.create_per_minute' => 1000, 'feitoo.rate_limits.upload_per_minute' => 1000,
            'feitoo.rate_limits.access_per_minute' => 1000, 'monetization.free.per_processing' => 20,
            'monetization.free.daily' => 50, 'monetization.stripe.secret_key' => 'sk_test_fake',
            'monetization.stripe.webhook_secret' => 'whsec_test']);
    }

    private function batch(int $count = 1): Processing
    {
        $uuid = $this->postJson('/api/processings', ['tool' => 'xml-to-excel'])->assertCreated()->json('data.id');
        foreach (array_chunk(range(1, $count), 20) as $chunk) {
            $files = array_map(fn ($n) => UploadedFile::fake()->createWithContent('test-'.$n.'.xml', '<document/>'), $chunk);
            $this->postJson('/api/processings/'.$uuid.'/files', ['files' => $files])->assertOk();
        }

        return Processing::where('uuid', $uuid)->firstOrFail();
    }

    private function url(Processing $p): string
    {
        return '/api/processings/'.$p->uuid;
    }

    private function purchase(Processing $p, PurchaseStatus $status = PurchaseStatus::Pending): Purchase
    {
        return Purchase::create(['user_id' => $p->user_id, 'processing_id' => $p->id, 'package_code' => 'xml_100',
            'files_limit' => 100, 'files_count' => $p->files_count, 'amount' => 490, 'currency' => 'brl',
            'status' => $status, 'expires_at' => now()->addMinutes(35), 'provider_checkout_session_id' => 'cs_test_example']);
    }

    private function webhook(Purchase $purchase, array $overrides = [], string $eventId = 'evt_test_one', string $type = 'checkout.session.completed')
    {
        $object = array_replace_recursive(['id' => $purchase->provider_checkout_session_id, 'object' => 'checkout.session', 'livemode' => false,
            'mode' => 'payment', 'payment_status' => 'paid', 'amount_total' => $purchase->amount, 'currency' => $purchase->currency,
            'payment_intent' => 'pi_test_one', 'metadata' => ['purchase_uuid' => $purchase->uuid,
                'processing_uuid' => $purchase->processing->uuid, 'package_code' => $purchase->package_code]], $overrides);
        $body = json_encode(['id' => $eventId, 'object' => 'event', 'type' => $type, 'livemode' => false, 'data' => ['object' => $object]]);
        $time = time();
        $signature = hash_hmac('sha256', $time.'.'.$body, 'whsec_test');

        return $this->call('POST', '/api/stripe/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => 't='.$time.',v1='.$signature], $body);
    }

    public function test_free_usage_is_configured_and_requires_session(): void
    {
        $this->getJson('/api/account/usage')->assertOk()->assertJsonPath('data.plan', 'free')
            ->assertJsonPath('data.limits.used_today', 0)->assertJsonPath('data.limits.remaining_today', 50)
            ->assertJsonPath('data.limits.per_processing', 20);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/account/usage')->assertUnauthorized();
    }

    public function test_accepted_start_consumes_once_and_upload_and_invalid_xml_do_not(): void
    {
        $p = $this->batch(10);
        $this->getJson('/api/account/usage')->assertJsonPath('data.limits.used_today', 0);
        $this->postJson($this->url($p).'/files', ['files' => [UploadedFile::fake()->createWithContent('bad.xml', '<broken>')]])->assertUnprocessable();
        $this->postJson($this->url($p).'/start')->assertStatus(202);
        $this->postJson($this->url($p).'/start')->assertConflict();
        $second = $this->batch(5);
        $this->postJson($this->url($second).'/start')->assertStatus(202);
        $this->getJson('/api/account/usage')->assertJsonPath('data.limits.used_today', 15)->assertJsonPath('data.limits.remaining_today', 35);
        $this->deleteJson($this->url($p).'/files')->assertOk();
        $this->getJson('/api/account/usage')->assertJsonPath('data.limits.used_today', 15);
    }

    public function test_limits_are_server_side_and_explicit_partial_choice_rolls_back_when_denied(): void
    {
        $p = $this->batch(21);
        $this->postJson($this->url($p).'/start')->assertUnprocessable()->assertJsonPath('code', 'purchase_required');
        $this->assertNull($p->fresh()->entitlement_source);
        $this->postJson($this->url($p).'/start', ['free_files_count' => 20])->assertStatus(202)->assertJsonPath('data.files_count', 20);
        $this->assertSame(1, $p->files()->whereNotNull('removed_at')->count());
        config(['monetization.free.daily' => 25]);
        $q = $this->batch(10);
        $this->postJson($this->url($q).'/start', ['free_files_count' => 6])->assertUnprocessable()->assertJsonPath('code', 'usage_limit_exceeded');
        $this->assertSame(10, $q->fresh()->files_count);
        $this->assertSame(0, $q->files()->whereNotNull('removed_at')->count());
        $this->postJson($this->url($q).'/start', ['free_files_count' => 5])->assertStatus(202);
        $this->getJson('/api/account/usage')->assertJsonPath('data.limits.remaining_today', 0);
    }

    public function test_daily_reset_uses_sao_paulo_and_not_utc(): void
    {
        $this->travelTo(Carbon::parse('2026-10-09T02:59:00Z'));
        $p = $this->batch(3);
        $this->postJson($this->url($p).'/start')->assertStatus(202);
        $this->assertSame('2026-10-08', $p->fresh()->usage_date);
        $this->getJson('/api/account/usage')->assertJsonPath('data.limits.used_today', 3)->assertJsonPath('data.limits.resets_at', '2026-10-09T00:00:00-03:00');
        $this->travelTo(Carbon::parse('2026-10-09T03:00:00Z'));
        $this->getJson('/api/account/usage')->assertJsonPath('data.limits.used_today', 0);
    }

    public function test_purchase_uses_backend_price_smallest_package_and_one_checkout(): void
    {
        $p = $this->batch(147);
        $mock = Mockery::mock(StripeCheckoutGateway::class);
        $mock->shouldReceive('available')->andReturn(true);
        $mock->shouldReceive('create')->once()->withArgs(function (Purchase $purchase, Processing $processing) {
            $this->assertSame(690, $purchase->amount);
            $this->assertSame('xml_250', $purchase->package_code);
            $parameters = (new StripeCheckoutGateway)->parameters($purchase, $processing);
            $this->assertSame(690, $parameters['line_items'][0]['price_data']['unit_amount']);
            $this->assertSame(['purchase_uuid', 'processing_uuid', 'package_code'], array_keys($parameters['metadata']));
            $this->assertStringNotContainsString('test-1.xml', json_encode($parameters));

            return true;
        })->andReturn(Session::constructFrom(['id' => 'cs_test_created', 'url' => 'https://checkout.stripe.com/c/pay/test', 'livemode' => false]));
        $this->app->instance(StripeCheckoutGateway::class, $mock);
        $this->postJson($this->url($p).'/purchase', ['price' => 1, 'files_limit' => 1000])->assertOk()->assertJsonPath('data.purchase.amount', 690);
        $this->postJson($this->url($p).'/purchase')->assertOk();
        $this->assertDatabaseCount('purchases', 1);
        $this->postJson($this->url($p).'/files', ['files' => [UploadedFile::fake()->createWithContent('extra.xml', '<document/>')]])->assertUnprocessable()->assertJsonPath('code', 'purchase_volume_locked');
    }

    public function test_ownership_and_purchase_resources_do_not_leak_stripe_fields(): void
    {
        $p = $this->batch();
        $this->purchase($p);
        $this->getJson('/api/account/purchases')->assertOk()->assertJsonMissingPath('data.0.provider_checkout_session_id')->assertJsonMissingPath('data.0.checkout_url');
        $this->app['auth']->forgetGuards();
        $this->actingAs(User::factory()->create(), 'web');
        $this->postJson($this->url($p).'/purchase')->assertNotFound();
        $this->getJson($this->url($p).'/purchase')->assertNotFound();
        $this->getJson('/api/account/purchases')->assertJsonCount(0, 'data');
    }

    public function test_webhook_is_signed_idempotent_and_paid_purchase_does_not_consume_free(): void
    {
        $p = $this->batch(21);
        $purchase = $this->purchase($p);
        $this->postJson('/api/stripe/webhook', [])->assertBadRequest();
        $this->postJson($this->url($p).'/start')->assertUnprocessable()->assertJsonPath('code', 'payment_pending');
        $this->webhook($purchase)->assertOk();
        $this->webhook($purchase)->assertOk();
        $this->assertDatabaseCount('stripe_webhook_events', 1);
        $this->assertSame(PurchaseStatus::Paid, $purchase->fresh()->status);
        $this->assertSame(ProcessingStatus::Pending, $p->fresh()->status);
        $this->getJson($this->url($p).'/purchase')->assertJsonPath('data.can_resume_processing', true);
        $this->postJson($this->url($p).'/start')->assertStatus(202);
        $this->postJson($this->url($p).'/start')->assertConflict();
        $this->getJson('/api/account/usage')->assertJsonPath('data.limits.used_today', 0);
        $this->assertNotNull($purchase->fresh()->consumed_at);
        $this->getJson($this->url($p).'/purchase')->assertJsonPath('data.can_resume_processing', false);
    }

    public function test_wrong_amount_unpaid_live_or_wrong_session_never_grant_access(): void
    {
        $p = $this->batch();
        $purchase = $this->purchase($p);
        foreach ([['amount_total' => 1], ['payment_status' => 'unpaid'], ['livemode' => true], ['id' => 'cs_test_other']] as $i => $data) {
            $this->webhook($purchase, $data, 'evt_wrong_'.$i)->assertOk();
            $this->assertSame(PurchaseStatus::Pending, $purchase->fresh()->status);
        }
    }

    public function test_expiration_without_provider_closure_still_blocks_free_and_signed_expiration_releases(): void
    {
        $p = $this->batch();
        $purchase = $this->purchase($p);
        $purchase->update(['expires_at' => now()->subSecond()]);
        $this->getJson($this->url($p).'/purchase')->assertJsonPath('data.status', 'expired');
        $this->postJson($this->url($p).'/start')->assertUnprocessable()->assertJsonPath('code', 'payment_pending');
        $this->webhook($purchase, [], 'evt_expire', 'checkout.session.expired')->assertOk();
        $this->postJson($this->url($p).'/start')->assertStatus(202);
    }

    public function test_full_refund_revokes_purchase_and_late_completion_does_not_restore_it(): void
    {
        $p = $this->batch(21);
        $purchase = $this->purchase($p);
        $this->webhook($purchase)->assertOk();
        $this->webhook($purchase, ['id' => 'ch_test', 'amount' => 490, 'amount_refunded' => 490], 'evt_refund', 'charge.refunded')->assertOk();
        $this->webhook($purchase, [], 'evt_late')->assertOk();
        $this->assertSame(PurchaseStatus::Refunded, $purchase->fresh()->status);
        $this->postJson($this->url($p).'/start')->assertUnprocessable()->assertJsonPath('code', 'purchase_required');
    }

    public function test_cancellation_requires_provider_expiration_and_does_not_start_work(): void
    {
        $p = $this->batch(21);
        $purchase = $this->purchase($p);
        $gateway = Mockery::mock(StripeCheckoutGateway::class);
        $gateway->shouldReceive('expire')->once()->with('cs_test_example')->andReturn(Session::constructFrom(['id' => 'cs_test_example', 'status' => 'expired', 'livemode' => false]));
        $this->app->instance(StripeCheckoutGateway::class, $gateway);
        $this->postJson($this->url($p).'/purchase/cancel')->assertOk()->assertJsonPath('data.status', 'expired');
        $this->assertSame(ProcessingStatus::Pending, $p->fresh()->status);
        $this->assertNotNull($purchase->fresh()->checkout_closed_at);
        $this->postJson($this->url($p).'/start', ['free_files_count' => 20])->assertStatus(202);
        $this->assertSame(20, $p->fresh()->usage_files_count);
    }

    public function test_live_secret_is_disabled_and_large_unbenchmarked_packages_are_not_exposed(): void
    {
        config(['monetization.stripe.secret_key' => 'sk_live_example']);
        $this->getJson('/api/tools/xml-to-excel/config')->assertJsonPath('data.checkout_available', false)->assertJsonCount(2, 'data.packages');
        $p = $this->batch();
        $this->postJson($this->url($p).'/purchase')->assertStatus(503);
        $this->assertDatabaseCount('purchases', 0);
    }

    public function test_purchase_history_is_paginated_and_status_expiration_does_not_fabricate_paid(): void
    {
        foreach (range(1, 12) as $i) {
            $p = $this->batch();
            $purchase = $this->purchase($p);
            $purchase->update(['provider_checkout_session_id' => 'cs_test_'.$i, 'created_at' => now()->addSeconds($i)]);
        }
        $this->getJson('/api/account/purchases')->assertOk()->assertJsonCount(10, 'data')->assertJsonPath('meta.total', 12);
        $this->getJson('/api/account/purchases?page=2')->assertJsonCount(2, 'data');
    }
}
