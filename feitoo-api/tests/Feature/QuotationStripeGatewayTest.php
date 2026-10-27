<?php

namespace Tests\Feature;

use App\Exceptions\QuotationGenerationException;
use App\Models\QuotationCreditOrder;
use App\Services\Quotations\QuotationStripeGateway;
use Mockery;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\TestCase;

class QuotationStripeGatewayTest extends TestCase
{
    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        parent::tearDown();
    }

    public function test_sdk_uses_verified_test_price_technical_metadata_and_stable_key(): void
    {
        config(['quotations.credit_price_id' => 'price_fixture']);
        $order = new QuotationCreditOrder(['uuid' => 'technical-uuid', 'price_id' => 'price_fixture', 'amount' => 990, 'currency' => 'brl', 'expires_at' => now()->addHour()]);
        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')->once()->ordered()->withArgs(function ($method, $url) {
            return $method === 'get' && str_ends_with($url, '/v1/prices/price_fixture');
        })->andReturn([json_encode(['id' => 'price_fixture', 'object' => 'price', 'livemode' => false, 'active' => true, 'currency' => 'brl', 'unit_amount' => 990, 'type' => 'one_time']), 200, []]);
        $http->shouldReceive('request')->once()->ordered()->withArgs(function ($method, $url, $headers, $params) {
            $this->assertSame(['quotation_order' => 'technical-uuid', 'operation' => 'quotation_credits'], $params['metadata']);
            $this->assertSame($params['metadata'], $params['payment_intent_data']['metadata']);
            $this->assertSame([['price' => 'price_fixture', 'quantity' => 1]], $params['line_items']);
            $this->assertStringContainsString('quotation_order=technical-uuid', $params['success_url']);
            $this->assertStringContainsString('quotation-order-technical-uuid', implode(' ', $headers));
            $this->assertArrayNotHasKey('customer_email', $params);
            $this->assertArrayNotHasKey('price_data', $params['line_items'][0]);

            return $method === 'post' && str_ends_with($url, '/v1/checkout/sessions');
        })->andReturn([json_encode(['id' => 'cs_test_fixture', 'object' => 'checkout.session', 'livemode' => false, 'url' => 'https://checkout.stripe.com/c/pay/fixture']), 200, []]);
        ApiRequestor::setHttpClient($http);
        $this->assertSame('cs_test_fixture', app(QuotationStripeGateway::class)->checkout($order)->id);
    }

    public function test_missing_price_live_or_wrong_price_fails_closed(): void
    {
        ApiRequestor::setHttpClient(Mockery::mock(ClientInterface::class));
        config(['quotations.credit_price_id' => null]);
        $this->assertFalse(app(QuotationStripeGateway::class)->available());
        config(['quotations.credit_price_id' => 'price_fixture', 'monetization.stripe.mode' => 'live', 'monetization.stripe.secret_key' => 'sk_live_fixture']);
        $this->assertFalse(app(QuotationStripeGateway::class)->available());
        try {
            app(QuotationStripeGateway::class)->session('cs_live_fixture');
            $this->fail('Live accepted');
        } catch (QuotationGenerationException $e) {
            $this->assertSame('checkout_unavailable', $e->reason);
        }
        config(['monetization.stripe.mode' => 'test', 'monetization.stripe.secret_key' => 'sk_test_fixture']);
        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')->once()->andReturn([json_encode(['id' => 'price_fixture', 'object' => 'price', 'livemode' => false, 'active' => true, 'currency' => 'brl', 'unit_amount' => 1, 'type' => 'one_time']), 200, []]);
        ApiRequestor::setHttpClient($http);
        $this->expectException(QuotationGenerationException::class);
        app(QuotationStripeGateway::class)->checkout(new QuotationCreditOrder(['price_id' => 'price_fixture']));
    }
}
