<?php

return [
    'free' => [
        'per_processing' => (int) env('FREE_MAX_FILES_PER_PROCESSING', 20),
        'daily' => (int) env('FREE_DAILY_FILES', 50),
        'timezone' => 'America/Sao_Paulo',
    ],
    'pro' => [
        'price_id' => env('STRIPE_PRO_PRICE_ID'),
        'amount' => (int) env('PRO_MONTHLY_CENTS', 2990),
        'per_processing' => (int) env('PRO_MAX_FILES_PER_PROCESSING', 250),
        'daily' => (int) env('PRO_DAILY_FILES', 500),
        'ttl_minutes' => (int) env('PRO_RETENTION_MINUTES', 1440),
    ],
    'stripe' => [
        'mode' => env('STRIPE_MODE', 'test'),
        'secret_key' => env('STRIPE_SECRET_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'checkout_minutes' => 35,
    ],
    'packages' => [
        'xml_100' => ['files_limit' => 100, 'amount' => (int) env('SINGLE_PURCHASE_100_CENTS', 490), 'currency' => 'brl'],
        'xml_250' => ['files_limit' => 250, 'amount' => (int) env('SINGLE_PURCHASE_250_CENTS', 690), 'currency' => 'brl'],
        'xml_500' => ['files_limit' => 500, 'amount' => (int) env('SINGLE_PURCHASE_500_CENTS', 990), 'currency' => 'brl'],
        'xml_1000' => ['files_limit' => 1000, 'amount' => (int) env('SINGLE_PURCHASE_1000_CENTS', 1490), 'currency' => 'brl'],
    ],
];
