<?php

namespace Tests\Feature;

use App\Exceptions\BillingException;
use App\Models\Subscription;
use App\Models\User;
use App\Services\StripeEnvironment;
use App\Services\StripeSubscriptionGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\TestCase;

class StripeEnvironmentTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        parent::tearDown();
    }

    private function mode(string $mode): void
    {
        config(['monetization.stripe.mode' => $mode, 'monetization.stripe.secret_key' => 'sk_'.$mode.'_fake',
            'monetization.pro.price_id' => 'price_pro']);
    }

    private function responses(array $objects): void
    {
        $http = Mockery::mock(ClientInterface::class);
        foreach ($objects as $object) {
            $http->shouldReceive('request')->once()->ordered()->withArgs(function ($method, $url, $headers, $params) {
                if ($method === 'post' && str_ends_with($url, '/checkout/sessions')) {
                    foreach (['payment_method_types', 'allowed_payment_method_types', 'excluded_payment_method_types', 'automatic_payment_methods'] as $key) {
                        $this->assertArrayNotHasKey($key, $params);
                    }
                    $this->assertSame('subscription', $params['mode']);
                    $this->assertSame('price_pro', $params['line_items'][0]['price']);
                    $this->assertSame(1, $params['line_items'][0]['quantity']);
                    $this->assertStringContainsString('Idempotency-Key', implode(' ', $headers));
                }

                return true;
            })->andReturn([json_encode($object), 200, []]);
        }
        ApiRequestor::setHttpClient($http);
    }

    public function test_mode_key_matrix_and_invalid_modes(): void
    {
        foreach (['test', 'live', '', 'invalid'] as $mode) {
            foreach (['sk', 'rk'] as $prefix) {
                foreach (['test', 'live'] as $key) {
                    config(['monetization.stripe.mode' => $mode, 'monetization.stripe.secret_key' => $prefix.'_'.$key.'_fake']);
                    $this->assertSame($mode === $key, app(StripeEnvironment::class)->available(), $mode.' + '.$prefix.'_'.$key);
                    if ($mode === $key) {
                        app(StripeEnvironment::class)->assertConfigured();
                    } else {
                        try {
                            app(StripeEnvironment::class)->assertConfigured();
                            $this->fail('Configuration accepted');
                        } catch (BillingException $e) {
                            $this->assertSame('stripe_configuration_invalid', $e->reason);
                            $this->assertStringNotContainsString($prefix.'_', $e->getMessage());
                        }
                    }
                }
            }
        }
    }

    public function test_checkout_mode_and_prefix_mismatches_rejected(): void
    {
        foreach (['test', 'live'] as $mode) {
            $this->mode($mode);
            foreach ([['livemode' => $mode !== 'live', 'id' => 'cs_'.$mode.'_x'],
                ['livemode' => $mode === 'live', 'id' => 'cs_'.($mode === 'live' ? 'test' : 'live').'_x']] as $session) {
                try {
                    app(StripeEnvironment::class)->assertSession((object) $session);
                    $this->fail('Mismatch accepted');
                } catch (BillingException $e) {
                    $this->assertSame('stripe_mode_mismatch', $e->reason);
                }
            }
        }
    }

    public function test_customer_price_checkout_subscription_and_portal_in_both_modes(): void
    {
        foreach (['test', 'live'] as $mode) {
            $this->mode($mode);
            $live = $mode === 'live';
            $customer = ['object' => 'customer', 'id' => 'cus_example', 'livemode' => $live];
            $this->responses([$customer,
                ['object' => 'price', 'id' => 'price_pro', 'livemode' => $live, 'active' => true, 'currency' => 'brl',
                    'unit_amount' => 2990, 'recurring' => ['interval' => 'month', 'interval_count' => 1]],
                $customer,
                ['object' => 'checkout.session', 'id' => 'cs_'.$mode.'_x', 'livemode' => $live, 'url' => 'https://checkout.stripe.com/x'],
                ['object' => 'subscription', 'id' => 'sub_example', 'livemode' => $live], $customer,
                ['object' => 'billing_portal.session', 'id' => 'bps_x', 'customer' => 'cus_example', 'livemode' => $live,
                    'url' => 'https://billing.stripe.com/p/session/x']]);
            $gateway = app(StripeSubscriptionGateway::class);
            $this->assertSame('cus_example', $gateway->customer(User::factory()->create()));
            $sub = new Subscription(['provider_customer_id' => 'cus_example', 'provider_price_id' => 'price_pro']);
            $this->assertSame($live, $gateway->checkout($sub)->livemode);
            $this->assertSame($live, $gateway->retrieve('sub_example')->livemode);
            $this->assertSame('https://billing.stripe.com/p/session/x', $gateway->portal('cus_example'));
        }
    }

    public function test_price_customer_and_portal_mismatch_rejected(): void
    {
        $this->mode('live');
        foreach (['price', 'customer', 'billing_portal.session'] as $type) {
            $objects = $type === 'billing_portal.session' ? [['object' => 'customer', 'id' => 'cus_x', 'livemode' => true]] : [];
            $objects[] = ['object' => $type, 'id' => 'example', 'livemode' => false];
            $this->responses($objects);
            $gateway = app(StripeSubscriptionGateway::class);
            try {
                match ($type) {
                    'price' => $gateway->checkout(new Subscription(['provider_price_id' => 'price_pro'])),
                    'customer' => $gateway->customer(User::factory()->create()),
                    default => $gateway->portal('cus_x'),
                };
                $this->fail('Mismatch accepted');
            } catch (BillingException $e) {
                $this->assertSame('stripe_mode_mismatch', $e->reason);
            }
        }
    }

    public function test_signed_live_webhook_is_idempotent_and_test_event_rejected(): void
    {
        $this->mode('live');
        foreach ([false, true, true] as $live) {
            $body = json_encode(['id' => 'evt_live', 'object' => 'event', 'type' => 'checkout.session.expired',
                'livemode' => $live, 'data' => ['object' => ['object' => 'checkout.session', 'id' => 'cs_live_x', 'livemode' => $live]]]);
            $time = time();
            $signature = hash_hmac('sha256', $time.'.'.$body, 'whsec_test');
            $this->call('POST', '/api/stripe/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => 't='.$time.',v1='.$signature], $body)->assertStatus($live ? 200 : 400);
        }
        $this->assertDatabaseCount('stripe_webhook_events', 1);
    }
}
