<?php

namespace Tests\Feature;

use App\Exceptions\BillingException;
use App\Exceptions\QuotationGenerationException;
use App\Models\QuotationCreditOrder;
use App\Services\Quotations\QuotationStripeGateway;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\TestCase;

class QuotationStripeGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['quotations.purchases_enabled' => true]);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        parent::tearDown();
    }

    public static function modes(): array
    {
        return ['TEST' => ['test'], 'LIVE simulated' => ['live'], 'Account TEST' => ['test', 'account'], 'Account LIVE simulated' => ['live', 'account']];
    }

    #[DataProvider('modes')]
    public function test_sdk_uses_verified_price_product_metadata_and_stable_key_in_both_modes(string $mode, string $destination = 'editor'): void
    {
        config(['quotations.credit_price_id' => 'price_fixture', 'monetization.stripe.mode' => $mode, 'monetization.stripe.secret_key' => 'sk_'.$mode.'_fixture']);
        $order = new QuotationCreditOrder(['uuid' => 'technical-uuid', 'return_destination' => $destination, 'stripe_mode' => $mode, 'price_id' => 'price_fixture', 'amount' => 990, 'currency' => 'brl', 'expires_at' => now()->addHour()]);
        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')->once()->ordered()->withArgs(function ($method, $url, $headers, $params) {
            $this->assertSame(['product'], $params['expand']);

            return $method === 'get' && str_ends_with($url, '/v1/prices/price_fixture');
        })->andReturn([json_encode($this->price($mode)), 200, []]);
        $http->shouldReceive('request')->once()->ordered()->withArgs(function ($method, $url, $headers, $params) use ($destination) {
            $this->assertSame(['quotation_order' => 'technical-uuid', 'operation' => 'quotation_credits'], $params['metadata']);
            $this->assertSame($params['metadata'], $params['payment_intent_data']['metadata']);
            $this->assertSame([['price' => 'price_fixture', 'quantity' => 1]], $params['line_items']);
            $this->assertSame('false', $params['adaptive_pricing']['enabled']);
            $this->assertSame('false', $params['automatic_tax']['enabled']);
            $this->assertSame('false', $params['allow_promotion_codes']);
            $this->assertStringContainsString(($destination === 'account' ? '/conta/creditos' : '/gerador-de-orcamentos').'?quotation_order=technical-uuid', $params['success_url']);
            $this->assertStringContainsString($destination === 'account' ? '/conta/creditos?' : '/gerador-de-orcamentos?', $params['cancel_url']);
            $this->assertStringContainsString('quotation-order-technical-uuid', implode(' ', $headers));
            $this->assertArrayNotHasKey('customer_email', $params);
            $this->assertArrayNotHasKey('price_data', $params['line_items'][0]);

            return $method === 'post' && str_ends_with($url, '/v1/checkout/sessions');
        })->andReturn([json_encode(['id' => 'cs_'.$mode.'_fixture', 'object' => 'checkout.session', 'livemode' => $mode === 'live', 'url' => 'https://checkout.stripe.com/c/pay/fixture']), 200, []]);
        ApiRequestor::setHttpClient($http);
        $this->assertSame('cs_'.$mode.'_fixture', app(QuotationStripeGateway::class)->checkout($order)->id);
    }

    public function test_missing_price_or_incoherent_key_fails_closed(): void
    {
        ApiRequestor::setHttpClient(Mockery::mock(ClientInterface::class));
        config(['quotations.credit_price_id' => null]);
        $this->assertFalse(app(QuotationStripeGateway::class)->available());
        config(['quotations.credit_price_id' => 'price_fixture', 'monetization.stripe.mode' => 'live', 'monetization.stripe.secret_key' => 'sk_test_fixture']);
        $this->assertFalse(app(QuotationStripeGateway::class)->available());
        $this->expectException(QuotationGenerationException::class);
        app(QuotationStripeGateway::class)->checkout(new QuotationCreditOrder(['stripe_mode' => 'live']));
    }

    public function test_ambiguous_session_recovery_only_lists_original_time_window_and_matches_technical_identity(): void
    {
        $order = new QuotationCreditOrder(['uuid' => 'technical-uuid', 'stripe_mode' => 'test', 'created_at' => now()->subHours(2), 'expires_at' => now()->subHour()]);
        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')->once()->withArgs(function ($method, $url, $headers, $params) use ($order) {
            $this->assertSame($order->created_at->timestamp - 5, $params['created']['gte']);
            $this->assertSame($order->expires_at->timestamp, $params['created']['lte']);

            return $method === 'get' && str_ends_with($url, '/v1/checkout/sessions');
        })->andReturn([json_encode(['object' => 'list', 'url' => '/v1/checkout/sessions', 'has_more' => false, 'data' => [
            ['object' => 'checkout.session', 'id' => 'cs_test_other', 'metadata' => [], 'client_reference_id' => 'other'],
            ['object' => 'checkout.session', 'id' => 'cs_test_original', 'livemode' => false, 'client_reference_id' => $order->uuid,
                'metadata' => ['operation' => 'quotation_credits', 'quotation_order' => $order->uuid]],
        ]]), 200, []]);
        ApiRequestor::setHttpClient($http);
        $this->assertSame('cs_test_original', app(QuotationStripeGateway::class)->findSession($order)->id);
    }

    private function price(string $mode): array
    {
        return ['id' => 'price_fixture', 'object' => 'price', 'livemode' => $mode === 'live', 'active' => true,
            'currency' => 'brl', 'unit_amount' => 990, 'type' => 'one_time',
            'product' => ['id' => 'prod_fixture', 'object' => 'product', 'active' => true, 'livemode' => $mode === 'live',
                'metadata' => ['code' => 'quotation_5', 'credits' => '5']]];
    }

    public static function invalidPrices(): array
    {
        return ['wrong value' => [['unit_amount' => 1]], 'currency' => [['currency' => 'usd']], 'recurring' => [['type' => 'recurring']],
            'inactive price' => [['active' => false]], 'cross mode price' => [['livemode' => true]],
            'inactive product' => [['product' => ['active' => false]]], 'cross mode product' => [['product' => ['livemode' => true]]],
            'wrong package' => [['product' => ['metadata' => ['code' => 'xml_100']]]], 'wrong credits' => [['product' => ['metadata' => ['credits' => '10']]]]];
    }

    #[DataProvider('invalidPrices')]
    public function test_invalid_price_or_product_never_creates_a_session(array $overrides): void
    {
        config(['quotations.credit_price_id' => 'price_fixture']);
        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')->once()->withArgs(fn ($method) => $method === 'get')
            ->andReturn([json_encode(array_replace_recursive($this->price('test'), $overrides)), 200, []]);
        ApiRequestor::setHttpClient($http);
        try {
            app(QuotationStripeGateway::class)->checkout(new QuotationCreditOrder(['stripe_mode' => 'test', 'price_id' => 'price_fixture']));
            $this->fail('Invalid price accepted');
        } catch (\Throwable $error) {
            $this->assertContains($error::class, [QuotationGenerationException::class, BillingException::class]);
        }
    }
}
