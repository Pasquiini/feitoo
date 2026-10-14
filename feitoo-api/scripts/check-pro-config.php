<?php

use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
echo json_encode([
    'test_key_configured' => str_starts_with((string) config('monetization.stripe.secret_key'), 'sk_test_'),
    'webhook_configured' => (bool) config('monetization.stripe.webhook_secret'),
    'pro_price_configured' => (bool) config('monetization.pro.price_id'),
], JSON_PRETTY_PRINT).PHP_EOL;
