<?php
// No Laravel boot, no DB, no secret/ID values and no external HTTP.
$api = dirname(__DIR__, 2).'/feitoo-api';
require $api.'/vendor/autoload.php';
$file = $api.'/.env';
$values = file_exists($file) ? Dotenv\Dotenv::parse(file_get_contents($file)) : [];
$secret = $values['STRIPE_SECRET_KEY'] ?? '';
$mode = $values['STRIPE_MODE'] ?? 'test';
$data = [
    'source' => 'Local .env presence only; not the deployed production configuration',
    'mode' => $mode,
    'key_mode' => preg_match('/^(?:sk|rk)_(test|live)_/', $secret, $m) ? $m[1] : 'absent_or_invalid',
    'webhook_secret_present' => str_starts_with($values['STRIPE_WEBHOOK_SECRET'] ?? '', 'whsec_'),
    'pro_price_present' => str_starts_with($values['STRIPE_PRO_PRICE_ID'] ?? '', 'price_'),
    'quotation_test_price_present' => str_starts_with($values['STRIPE_QUOTATION_CREDITS_TEST_PRICE_ID'] ?? '', 'price_'),
    'quotation_live_price_present' => str_starts_with($values['STRIPE_QUOTATION_CREDITS_LIVE_PRICE_ID'] ?? '', 'price_'),
    'quotation_generation_flag' => $values['QUOTATION_GENERATION_ENABLED'] ?? '(default false)',
    'quotation_purchases_flag' => $values['QUOTATION_PURCHASES_ENABLED'] ?? '(default false)',
    'cached_configuration_file_present' => file_exists($api.'/bootstrap/cache/config.php'),
    'stripe_requests' => 0,
];
file_put_contents(__DIR__.'/config-presence.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
