<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['monetization.stripe.mode' => 'test', 'monetization.stripe.secret_key' => 'sk_test_fake',
            'monetization.stripe.webhook_secret' => 'whsec_test']);
        // All Stripe traffic must be explicitly simulated, including tests of reconciliation GETs.
        ApiRequestor::setHttpClient(\Mockery::mock(ClientInterface::class));
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        parent::tearDown();
    }
}
