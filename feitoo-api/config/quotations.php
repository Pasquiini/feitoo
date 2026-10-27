<?php

return [
    'enabled' => (bool) env('QUOTATION_GENERATION_ENABLED', env('APP_ENV') !== 'production'),
    'credit_price_id' => env('STRIPE_QUOTATION_CREDITS_TEST_PRICE_ID'),
    'pro_limit' => 100,
    'timezone' => 'America/Sao_Paulo',
    'pending_minutes' => 30,
    'result_minutes' => 120,
    'max_attempts' => 3,
    'lease_seconds' => 90,
    'render_seconds' => 30,
    'memory_mb' => 192,
    'renderer_version' => 'quotation-v1-dompdf-3.1',
    'php_binary' => env('QUOTATION_PHP_BINARY', PHP_SAPI === 'cli' ? PHP_BINARY : PHP_BINDIR.(PHP_OS_FAMILY === 'Windows' ? '/php.exe' : '/php')),
    'sandbox' => env('QUOTATION_SANDBOX', PHP_OS_FAMILY === 'Linux' ? 'bubblewrap' : 'php'),
    'bubblewrap_binary' => env('QUOTATION_BWRAP_BINARY', '/usr/bin/bwrap'),
    'prlimit_binary' => env('QUOTATION_PRLIMIT_BINARY', '/usr/bin/prlimit'),
];
