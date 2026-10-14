<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['monetization.stripe.mode' => 'test', 'monetization.stripe.secret_key' => 'sk_test_fake',
            'monetization.stripe.webhook_secret' => 'whsec_test']);
    }
}
