<?php

namespace Tests\Feature;

use App\Models\QuotationCreditOrder;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use Stripe\ApiRequestor;
use Stripe\Exception\ApiConnectionException;
use Stripe\HttpClient\ClientInterface;
use Tests\TestCase;

class QuotationCheckoutFailureTest extends TestCase
{
    use RefreshDatabase;

    private $http;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::getDriverName());
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->freezeSecond();
        config(['quotations.enabled' => true, 'quotations.purchases_enabled' => true,
            'quotations.credit_price_id' => 'price_fixture', 'feitoo.auth.frontend_url' => 'https://example.test',
            'monetization.stripe.mode' => 'test', 'monetization.stripe.secret_key' => 'sk_test_fixture',
            'monetization.stripe.webhook_secret' => 'whsec_fixture']);
        $this->actingAs(User::factory()->create(), 'web');
        $this->http = Mockery::mock(ClientInterface::class);
        ApiRequestor::setHttpClient($this->http);
        Log::spy();
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        parent::tearDown();
    }

    private function price(): void
    {
        $this->http->shouldReceive('request')->withArgs(fn ($method, $url) => $method === 'get' && str_ends_with($url, '/prices/price_fixture'))
            ->andReturn([json_encode(['object' => 'price', 'id' => 'price_fixture', 'livemode' => false, 'active' => true,
                'currency' => 'brl', 'unit_amount' => 990, 'type' => 'one_time',
                'product' => ['object' => 'product', 'id' => 'prod_fixture', 'active' => true, 'livemode' => false,
                    'metadata' => ['code' => 'quotation_5', 'credits' => '5']]]), 200, []]);
    }

    private function checkoutSession(array $overrides = []): array
    {
        $order = QuotationCreditOrder::sole();

        return array_replace(['object' => 'checkout.session', 'id' => 'cs_test_original', 'livemode' => false,
            'mode' => 'payment', 'status' => 'open', 'payment_status' => 'unpaid', 'payment_intent' => null,
            'client_reference_id' => $order->uuid, 'metadata' => ['quotation_order' => $order->uuid, 'operation' => 'quotation_credits'],
            'amount_total' => 990, 'currency' => 'brl', 'url' => 'https://checkout.stripe.com/c/pay/fixture'], $overrides);
    }

    private function create(string $key)
    {
        return $this->withHeader('Idempotency-Key', $key)->postJson('/api/quotations/credit-orders', ['return_destination' => 'account']);
    }

    public function test_account_checkout_through_real_sdk_persists_response_and_replays_without_another_post(): void
    {
        $this->price();
        $this->http->shouldReceive('request')->once()->withArgs(function ($method, $url, $headers, $params) {
            $order = QuotationCreditOrder::sole();
            $base = 'https://example.test/conta/creditos?quotation_order='.$order->uuid;
            $this->assertSame($base.'&payment=confirming', $params['success_url']);
            $this->assertSame($base.'&payment=cancelled', $params['cancel_url']);
            $this->assertSame($params['metadata'], $params['payment_intent_data']['metadata']);
            $this->assertStringContainsString('quotation-order-'.$order->uuid, implode(' ', $headers));

            return $method === 'post' && str_ends_with($url, '/checkout/sessions');
        })->andReturnUsing(fn () => [json_encode($this->checkoutSession()), 200, []]);
        $key = (string) Str::uuid();
        $response = $this->create($key)->assertCreated()->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.checkout_url', 'https://checkout.stripe.com/c/pay/fixture');
        $this->create($key)->assertCreated()->assertJsonPath('data.id', $response->json('data.id'));
        $this->assertDatabaseHas('quotation_credit_orders', ['session_id' => 'cs_test_original', 'return_destination' => 'account']);
        $this->assertDatabaseCount('quotation_credit_orders', 1);
        $this->assertDatabaseCount('quotation_credit_grants', 0);
    }

    public function test_provider_500_after_session_creation_recovers_immediately_with_get_only(): void
    {
        $this->price();
        $this->http->shouldReceive('request')->once()->withArgs(fn ($method) => $method === 'post')
            ->andReturn([json_encode(['error' => ['type' => 'api_error', 'message' => 'SECRET financial payload']]), 500, ['Request-Id' => 'req_fixture']]);
        $this->http->shouldReceive('request')->once()->withArgs(fn ($method, $url) => $method === 'get' && str_ends_with($url, '/checkout/sessions'))
            ->andReturnUsing(fn () => [json_encode(['object' => 'list', 'url' => '/v1/checkout/sessions', 'has_more' => false,
                'data' => [$this->checkoutSession()]]), 200, []]);
        $key = (string) Str::uuid();
        $this->create($key)->assertCreated()->assertJsonPath('data.checkout_url', 'https://checkout.stripe.com/c/pay/fixture');
        $this->create($key)->assertCreated();
        $this->assertDatabaseCount('quotation_credit_orders', 1);
        $this->assertDatabaseCount('quotation_credit_grants', 0);
    }

    public function test_network_timeout_without_remote_match_retains_original_key_and_payload_for_retry(): void
    {
        $this->price();
        $payloads = [];
        $this->http->shouldReceive('request')->twice()->withArgs(fn ($method) => $method === 'post')
            ->andReturnUsing(function ($method, $url, $headers, $params) use (&$payloads) {
                $payloads[] = [$headers, $params];
                if (count($payloads) === 1) {
                    throw new ApiConnectionException('SECRET credential or personal data');
                }

                return [json_encode($this->checkoutSession()), 200, []];
            });
        $this->http->shouldReceive('request')->once()->withArgs(fn ($method, $url) => $method === 'get' && str_ends_with($url, '/checkout/sessions'))
            ->andReturn([json_encode(['object' => 'list', 'url' => '/v1/checkout/sessions', 'has_more' => false, 'data' => []]), 200, []]);
        $key = (string) Str::uuid();
        $this->create($key)->assertStatus(503)->assertJsonPath('code', 'checkout_unavailable');
        $this->assertDatabaseHas('quotation_credit_orders', ['status' => 'pending', 'session_id' => null, 'checkout_url' => null]);
        $this->travel(1)->minutes();
        $this->create($key)->assertCreated();
        $this->assertSame($payloads[0], $payloads[1]);
        $this->assertDatabaseCount('quotation_credit_orders', 1);
        $this->assertDatabaseCount('quotation_credit_grants', 0);
        Log::shouldHaveReceived('error')->withArgs(function ($message, $context) {
            $this->assertSame('Quotation credit order failed.', $message);
            $this->assertSame('stripe_session_create', $context['stage']);
            $this->assertSame(ApiConnectionException::class, $context['exception_type']);
            $this->assertStringNotContainsString('SECRET', json_encode($context));

            return true;
        });
    }

    public function test_recovery_failure_keeps_order_pending_and_does_not_retry_creation(): void
    {
        $this->price();
        $this->http->shouldReceive('request')->once()->withArgs(fn ($method) => $method === 'post')
            ->andThrow(new ApiConnectionException('SECRET post timeout'));
        $this->http->shouldReceive('request')->once()->withArgs(fn ($method, $url) => $method === 'get' && str_ends_with($url, '/checkout/sessions'))
            ->andThrow(new ApiConnectionException('SECRET recovery timeout'));
        $this->create((string) Str::uuid())->assertStatus(503);
        $this->assertDatabaseHas('quotation_credit_orders', ['status' => 'pending', 'session_id' => null]);
        $this->assertDatabaseCount('quotation_credit_orders', 1);
        $this->assertDatabaseCount('quotation_credit_grants', 0);
        Log::shouldHaveReceived('error')->withArgs(fn ($message, $context) => $context['stage'] === 'stripe_session_recovery');
    }

    public function test_recovery_of_already_paid_session_confirms_only_once(): void
    {
        $this->price();
        $this->http->shouldReceive('request')->withArgs(fn ($method, $url) => $method === 'get' && str_ends_with($url, '/line_items'))
            ->andReturn([json_encode(['object' => 'list', 'data' => [['quantity' => 1, 'price' => ['id' => 'price_fixture', 'unit_amount' => 990, 'currency' => 'brl', 'livemode' => false]]], 'has_more' => false]), 200, []]);
        $this->http->shouldReceive('request')->once()->withArgs(fn ($method) => $method === 'post')
            ->andThrow(new ApiConnectionException('SECRET lost response'));
        $this->http->shouldReceive('request')->once()->withArgs(fn ($method, $url) => $method === 'get' && str_ends_with($url, '/checkout/sessions'))
            ->andReturnUsing(fn () => [json_encode(['object' => 'list', 'url' => '/v1/checkout/sessions', 'has_more' => false,
                'data' => [$this->checkoutSession(['status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => 'pi_fixture', 'url' => null])]]), 200, []]);
        $key = (string) Str::uuid();
        $this->create($key)->assertCreated()->assertJsonPath('data.status', 'paid')->assertJsonPath('data.checkout_url', null);
        $this->create($key)->assertCreated()->assertJsonPath('data.status', 'paid');
        $this->assertDatabaseCount('quotation_credit_orders', 1);
        $this->assertDatabaseCount('quotation_credit_grants', 1);
    }

    public function test_provider_parameter_error_has_sanitized_diagnostics_without_recovery_or_new_key(): void
    {
        $this->price();
        $this->http->shouldReceive('request')->once()->withArgs(fn ($method) => $method === 'post')
            ->andReturn([json_encode(['error' => ['type' => 'invalid_request_error', 'code' => 'url_invalid',
                'param' => 'success_url', 'message' => 'SECRET sk_test_credential personal@example.test']]), 400, ['Request-Id' => 'req_fixture']]);
        $response = $this->create((string) Str::uuid())->assertStatus(503)->assertJsonPath('code', 'checkout_unavailable');
        $this->assertStringNotContainsString('SECRET', $response->getContent());
        Log::shouldHaveReceived('error')->once()->withArgs(function ($message, $context) {
            $this->assertSame('stripe_session_create', $context['stage']);
            $this->assertSame(400, $context['stripe_http_status']);
            $this->assertSame('req_fixture', $context['stripe_request_id']);
            $this->assertSame('url_invalid', $context['stripe_error_code']);
            $this->assertSame('success_url', $context['stripe_parameter']);
            $this->assertStringNotContainsString('SECRET', json_encode($context));
            $this->assertStringNotContainsString('personal@example.test', json_encode($context));

            return true;
        });
        $this->assertDatabaseHas('quotation_credit_orders', ['status' => 'pending', 'session_id' => null]);
    }

    public function test_database_failure_after_remote_creation_retries_same_session_and_logs_persistence_stage(): void
    {
        $this->price();
        $payloads = [];
        $this->http->shouldReceive('request')->twice()->withArgs(fn ($method) => $method === 'post')
            ->andReturnUsing(function ($method, $url, $headers, $params) use (&$payloads) {
                $payloads[] = [$headers, $params];

                return [json_encode($this->checkoutSession()), 200, []];
            });
        $fail = true;
        Event::listen('eloquent.updating: '.QuotationCreditOrder::class, function () use (&$fail) {
            if ($fail) {
                $fail = false;
                throw new QueryException('sqlite', 'UPDATE sensitive financial payload', ['SECRET binding'], new \PDOException('SECRET database details'));
            }
        });
        $key = (string) Str::uuid();
        $this->create($key)->assertStatus(500)->assertJsonPath('code', 'order_request_failed');
        $this->assertDatabaseHas('quotation_credit_orders', ['session_id' => null, 'checkout_url' => null, 'status' => 'pending']);
        $this->travel(1)->minutes();
        $this->create($key)->assertCreated()->assertJsonPath('data.checkout_url', 'https://checkout.stripe.com/c/pay/fixture');
        $this->assertSame($payloads[0], $payloads[1]);
        $this->assertDatabaseCount('quotation_credit_orders', 1);
        $this->assertDatabaseCount('quotation_credit_grants', 0);
        Log::shouldHaveReceived('error')->once()->withArgs(function ($message, $context) {
            $this->assertSame('session_persist', $context['stage']);
            $this->assertSame(QueryException::class, $context['exception_type']);
            $this->assertStringNotContainsString('SECRET', json_encode($context));
            $this->assertArrayNotHasKey('exception', $context);

            return true;
        });
    }

    public function test_incompatible_release_schema_is_identified_before_any_remote_request(): void
    {
        Schema::table('quotation_credit_orders', fn ($table) => $table->dropColumn('return_destination'));
        $this->create((string) Str::uuid())->assertStatus(500);
        Log::shouldHaveReceived('error')->once()->withArgs(fn ($message, $context) => $context['stage'] === 'order_persist'
            && $context['exception_type'] === QueryException::class);
        $this->assertDatabaseCount('quotation_credit_orders', 0);
    }

    public function test_mismatched_provider_response_is_never_persisted_or_used_as_redirect(): void
    {
        $this->price();
        $this->http->shouldReceive('request')->once()->withArgs(fn ($method) => $method === 'post')
            ->andReturnUsing(fn () => [json_encode($this->checkoutSession(['amount_total' => 1])), 200, []]);
        $this->create((string) Str::uuid())->assertStatus(422)->assertJsonPath('code', 'payment_mismatch');
        $this->assertDatabaseHas('quotation_credit_orders', ['session_id' => null, 'checkout_url' => null]);
        $this->assertDatabaseCount('quotation_credit_grants', 0);
        Log::shouldHaveReceived('error')->once()->withArgs(fn ($message, $context) => $context['stage'] === 'session_validate'
            && $context['reason'] === 'payment_mismatch');
    }

    public function test_expected_request_validation_is_not_logged_as_technical_failure(): void
    {
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/quotations/credit-orders', ['return_destination' => 'https://untrusted.test'])->assertStatus(422);
        Log::shouldNotHaveReceived('error');
        $this->assertDatabaseCount('quotation_credit_orders', 0);
    }
}
