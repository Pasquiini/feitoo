<?php
// Audit-only PHPUnit bootstrap. The application base is a disposable copy, without .env.
$repo = dirname(__DIR__, 2);
$api = $repo.'/feitoo-api';
$lab = $api.'/storage/app/private/account-audit-lab';
if (! is_dir($lab.'/bootstrap') || file_exists($lab.'/.env')) {
    throw new RuntimeException('The isolated audit base must exist and must not contain .env.');
}
foreach ([
    'APP_BASE_PATH' => $lab, 'APP_ENV' => 'testing', 'APP_DEBUG' => 'false',
    'APP_KEY' => 'base64:'.base64_encode('0123456789abcdef0123456789abcdef'),
    'APP_CONFIG_CACHE' => 'bootstrap/cache/audit-config.php',
    'APP_PACKAGES_CACHE' => 'bootstrap/cache/audit-packages.php',
    'APP_SERVICES_CACHE' => 'bootstrap/cache/audit-services.php',
    'APP_ROUTES_CACHE' => 'bootstrap/cache/audit-routes.php',
    'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '',
    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
    'LOG_CHANNEL' => 'null', 'MAIL_MAILER' => 'array', 'BROADCAST_CONNECTION' => 'null',
    'STRIPE_MODE' => 'test', 'STRIPE_SECRET_KEY' => 'sk_test_fake', 'STRIPE_WEBHOOK_SECRET' => 'whsec_fake',
    'STRIPE_PRO_PRICE_ID' => 'price_pro', 'FRONTEND_URL' => 'https://example.test',
] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
require $api.'/vendor/autoload.php';
// Unexpected SDK calls fail immediately. Existing tests install their own mocks.
\Stripe\ApiRequestor::setHttpClient(new class implements \Stripe\HttpClient\ClientInterface {
    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        throw new RuntimeException('External Stripe HTTP is blocked by the audit bootstrap.');
    }
});
