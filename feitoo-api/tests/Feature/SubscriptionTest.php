<?php

namespace Tests\Feature;

use App\Enums\SubscriptionStatus;
use App\Exceptions\BillingException;
use App\Models\Processing;
use App\Models\Subscription;
use App\Models\User;
use App\Services\EntitlementService;
use App\Services\StripeSubscriptionGateway;
use App\Services\StripeWebhookService;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Stripe\Checkout\Session;
use Stripe\Event;
use Tests\TestCase;

class SubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private function subscription(User $user, string $status = 'active'): Subscription
    {
        $user->forceFill(['stripe_customer_id' => 'cus_test_'.$user->id])->save();

        return Subscription::create(['user_id' => $user->id, 'provider_customer_id' => $user->stripe_customer_id,
            'provider_price_id' => 'price_pro', 'provider_subscription_id' => 'sub_test', 'status' => $status,
            'current_period_end' => now()->addMonth()]);
    }

    public function test_upgrade_downgrade_preserves_daily_usage_and_applies_pro_limits(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        Processing::create(['user_id' => $user->id, 'tool' => 'xml-to-excel', 'status' => 'completed',
            'entitlement_source' => 'free', 'usage_date' => now('America/Sao_Paulo')->toDateString(), 'usage_files_count' => 50]);
        $this->getJson('/api/account/usage')->assertJsonPath('data.plan', 'free')->assertJsonPath('data.limits.remaining_today', 0);
        $sub = $this->subscription($user);
        $this->getJson('/api/account/usage')->assertJsonPath('data.plan', 'pro')->assertJsonPath('data.limits.remaining_today', 450);
        $processing = Processing::create(['user_id' => $user->id, 'tool' => 'xml-to-excel', 'status' => 'pending', 'files_count' => 250]);
        app(EntitlementService::class)->accept($processing, $user);
        $this->assertSame('pro', $processing->fresh()->entitlement_source);
        $this->assertSame(300, app(EntitlementService::class)->usage($user)['limits']['used_today']);
        $sub->update(['status' => 'canceled']);
        $this->getJson('/api/account/usage')->assertJsonPath('data.plan', 'free')->assertJsonPath('data.limits.used_today', 300)->assertJsonPath('data.limits.remaining_today', 0);
    }

    public function test_only_active_unexpired_subscription_grants_pro_and_scheduled_cancellation_keeps_access(): void
    {
        $user = User::factory()->create();
        $sub = $this->subscription($user);
        $sub->update(['cancel_at_period_end' => true]);
        $this->assertTrue($sub->grantsPro());
        foreach (SubscriptionStatus::cases() as $status) {
            $sub->update(['status' => $status]);
            $this->assertSame($status === SubscriptionStatus::Active, $sub->grantsPro());
        }
        $sub->update(['status' => 'active', 'current_period_end' => now()->subSecond()]);
        $this->assertFalse($sub->grantsPro());
    }

    public function test_checkout_reuses_customer_session_and_rejects_frontend_price(): void
    {
        config(['monetization.pro.price_id' => 'price_pro']);
        $user = User::factory()->create();
        $this->actingAs($user);
        $gateway = Mockery::mock(StripeSubscriptionGateway::class)->makePartial();
        $gateway->shouldReceive('available')->andReturn(true);
        $gateway->shouldReceive('customer')->once()->andReturn('cus_reused');
        $gateway->shouldReceive('checkout')->once()->withArgs(fn ($sub) => $sub->provider_price_id === 'price_pro')->andReturn(Session::constructFrom([
            'id' => 'cs_test_pro', 'livemode' => false, 'url' => 'https://checkout.stripe.com/c/pay/test', 'expires_at' => now()->addHour()->timestamp]));
        $this->app->instance(StripeSubscriptionGateway::class, $gateway);
        $this->postJson('/api/account/subscription/checkout', ['price_id' => 'price_fake'])->assertUnprocessable();
        $this->postJson('/api/account/subscription/checkout')->assertOk();
        $this->postJson('/api/account/subscription/checkout')->assertOk();
        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertSame('cus_reused', $user->fresh()->stripe_customer_id);
        $parameters = $gateway->parameters(Subscription::first());
        $this->assertSame('subscription', $parameters['mode']);
        $this->assertSame('price_pro', $parameters['line_items'][0]['price']);
        $this->assertArrayNotHasKey('trial_period_days', $parameters['subscription_data']);
        $this->assertSame('free', app(EntitlementService::class)->usage($user)['plan']);
    }

    public function test_subscription_webhooks_are_idempotent_and_use_current_provider_state(): void
    {
        $user = User::factory()->create();
        $sub = $this->subscription($user, 'incomplete');
        $gateway = Mockery::mock(StripeSubscriptionGateway::class);
        $remote = ['id' => 'sub_test', 'customer' => $user->stripe_customer_id, 'livemode' => false,
            'metadata' => ['feitoo_subscription' => (string) $sub->id], 'status' => 'active',
            'items' => ['data' => [['price' => ['id' => 'price_pro'], 'current_period_start' => now()->timestamp, 'current_period_end' => now()->addMonth()->timestamp]]],
            'cancel_at_period_end' => true, 'canceled_at' => null];
        $gateway->shouldReceive('retrieve')->once()->andReturn(\Stripe\Subscription::constructFrom($remote));
        $this->app->instance(StripeSubscriptionGateway::class, $gateway);
        $event = Event::constructFrom(['id' => 'evt_pro', 'livemode' => false, 'type' => 'customer.subscription.updated',
            'data' => ['object' => ['id' => 'sub_test', 'customer' => $user->stripe_customer_id, 'status' => 'past_due']]]);
        app(StripeWebhookService::class)->handle($event);
        app(StripeWebhookService::class)->handle($event);
        $this->assertSame(SubscriptionStatus::Active, $sub->fresh()->status);
        $this->assertTrue($sub->fresh()->cancel_at_period_end);
        $this->assertDatabaseCount('stripe_webhook_events', 1);
    }

    public function test_invoice_failure_cancellation_and_recovery_sync_current_subscription(): void
    {
        $user = User::factory()->create();
        $sub = $this->subscription($user);
        foreach (['invoice.payment_failed' => 'past_due', 'invoice.paid' => 'active', 'customer.subscription.deleted' => 'canceled'] as $type => $status) {
            $gateway = Mockery::mock(StripeSubscriptionGateway::class);
            $gateway->shouldReceive('retrieve')->once()->andReturn(\Stripe\Subscription::constructFrom([
                'id' => 'sub_test', 'customer' => $user->stripe_customer_id, 'livemode' => false,
                'metadata' => ['feitoo_subscription' => (string) $sub->id], 'status' => $status,
                'items' => ['data' => [['price' => ['id' => 'price_pro'], 'current_period_end' => now()->addMonth()->timestamp]]],
                'cancel_at_period_end' => false, 'canceled_at' => null]));
            $this->app->instance(StripeSubscriptionGateway::class, $gateway);
            $this->app->forgetInstance(SubscriptionService::class);
            $object = ['id' => str_starts_with($type, 'invoice.') ? 'in_test' : 'sub_test', 'customer' => $user->stripe_customer_id,
                'parent' => ['subscription_details' => ['subscription' => 'sub_test']]];
            app(StripeWebhookService::class)->handle(Event::constructFrom(['id' => 'evt_'.$status, 'type' => $type, 'data' => ['object' => $object]]));
            $this->assertSame($status, $sub->fresh()->status->value);
        }
    }

    public function test_portal_uses_only_authenticated_customer_and_public_summary_hides_ids(): void
    {
        $user = User::factory()->create();
        $this->subscription($user);
        $gateway = Mockery::mock(StripeSubscriptionGateway::class);
        $gateway->shouldReceive('portal')->once()->with($user->stripe_customer_id)->andReturn('https://billing.stripe.com/p/session/test');
        $gateway->shouldReceive('available')->andReturn(true);
        $this->app->instance(StripeSubscriptionGateway::class, $gateway);
        $this->actingAs($user)->postJson('/api/account/subscription/portal', ['customer' => 'cus_other'])->assertOk();
        $response = $this->getJson('/api/account/subscription')->assertOk();
        $this->assertStringNotContainsString('cus_test', $response->getContent());
        $this->actingAs(User::factory()->create())->postJson('/api/account/subscription/portal')->assertNotFound();
    }

    public function test_signed_checkout_webhook_activates_pro_and_rejects_invalid_signature_and_live_event(): void
    {
        config(['monetization.stripe.webhook_secret' => 'whsec_pro']);
        $user = User::factory()->create();
        $sub = $this->subscription($user, 'incomplete');
        $gateway = Mockery::mock(StripeSubscriptionGateway::class);
        $gateway->shouldReceive('retrieve')->once()->with('sub_test')->andReturn(\Stripe\Subscription::constructFrom([
            'id' => 'sub_test', 'customer' => $user->stripe_customer_id, 'livemode' => false,
            'metadata' => ['feitoo_subscription' => (string) $sub->id], 'status' => 'active',
            'items' => ['data' => [['price' => ['id' => 'price_pro'], 'current_period_end' => now()->addMonth()->timestamp]]],
            'cancel_at_period_end' => false, 'canceled_at' => null]));
        $this->app->instance(StripeSubscriptionGateway::class, $gateway);
        $body = ['id' => 'evt_checkout_pro', 'object' => 'event', 'livemode' => false, 'type' => 'checkout.session.completed',
            'data' => ['object' => ['id' => 'cs_test_pro', 'object' => 'checkout.session', 'mode' => 'subscription',
                'subscription' => 'sub_test', 'customer' => $user->stripe_customer_id]]];
        $send = function (array $event, bool $valid = true) {
            $payload = json_encode($event);
            $timestamp = time();
            $signature = hash_hmac('sha256', $timestamp.'.'.$payload, $valid ? 'whsec_pro' : 'invalid');

            return $this->call('POST', '/api/stripe/webhook', [], [], [], [
                'CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => 't='.$timestamp.',v1='.$signature], $payload);
        };
        $send($body, false)->assertStatus(400);
        $send([...$body, 'livemode' => true])->assertStatus(400);
        $send($body)->assertOk();
        $send($body)->assertOk();
        $this->assertTrue($sub->fresh()->grantsPro());
    }

    public function test_ambiguous_checkout_failure_retries_persisted_attempt_and_does_not_duplicate_customer(): void
    {
        config(['monetization.pro.price_id' => 'price_pro']);
        $user = User::factory()->create();
        $gateway = Mockery::mock(StripeSubscriptionGateway::class);
        $gateway->shouldReceive('available')->andReturn(true);
        $gateway->shouldReceive('customer')->once()->andReturn('cus_retry');
        $attempts = [];
        $gateway->shouldReceive('checkout')->twice()->andReturnUsing(function ($sub) use (&$attempts) {
            $attempts[] = $sub->id;
            if (count($attempts) === 1) {
                throw new \RuntimeException('Ambiguous network failure');
            }

            return Session::constructFrom(['id' => 'cs_test_retry', 'livemode' => false,
                'url' => 'https://checkout.stripe.com/retry', 'expires_at' => now()->addHour()->timestamp]);
        });
        $this->app->instance(StripeSubscriptionGateway::class, $gateway);
        try {
            app(SubscriptionService::class)->checkout($user);
            $this->fail('Expected failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Ambiguous network failure', $exception->getMessage());
        }
        app(SubscriptionService::class)->checkout($user);
        $this->assertSame($attempts[0], $attempts[1]);
        $this->assertDatabaseCount('subscriptions', 1);
    }

    public function test_status_is_authenticated_owned_and_has_a_separate_sixty_per_minute_throttle(): void
    {
        Cache::flush();
        $this->getJson('/api/account/subscription')->assertUnauthorized();
        $owner = User::factory()->create();
        $this->subscription($owner);
        $this->actingAs($owner);
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/account/subscription')->assertOk()->assertJsonPath('data.subscription.status', 'active');
        }
        $response = $this->getJson('/api/account/subscription')->assertStatus(429)
            ->assertJsonPath('message', 'Muitas requisições. Tente novamente mais tarde.')
            ->assertHeader('Retry-After');
        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
        // Status reads must not exhaust the sensitive Checkout/Portal budget.
        $this->postJson('/api/account/subscription/checkout', ['price_id' => 'price_frontend'])->assertUnprocessable();
        $this->actingAs(User::factory()->create());
        $this->getJson('/api/account/subscription?user_id='.$owner->id)->assertOk()
            ->assertJsonPath('data.subscription', null);
        $this->actingAs($owner);
        $this->travel(61)->seconds();
        $this->getJson('/api/account/subscription')->assertOk();
    }

    public function test_pro_daily_limit_and_retention_are_resolved_centrally(): void
    {
        $user = User::factory()->create();
        $this->subscription($user);
        $service = app(EntitlementService::class);
        $this->assertSame(1440, $service->retentionMinutes($user));
        Processing::create(['user_id' => $user->id, 'tool' => 'xml-to-excel', 'status' => 'completed',
            'entitlement_source' => 'pro', 'usage_date' => now('America/Sao_Paulo')->toDateString(), 'usage_files_count' => 499]);
        $processing = Processing::create(['user_id' => $user->id, 'tool' => 'xml-to-excel', 'status' => 'pending', 'files_count' => 2]);
        $this->expectException(BillingException::class);
        $service->accept($processing, $user);
    }
}
