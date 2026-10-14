<?php

return [
    'auth' => ['frontend_url' => env('FRONTEND_URL', env('APP_ENV') === 'production' ? 'https://feitoo.com.br' : 'http://localhost:4200')],
    'processing' => [
        'disk' => env('FEITOO_PROCESSING_DISK', 'processing'),
        'ttl_minutes' => (int) env('FEITOO_PROCESSING_TTL_MINUTES', env('FEITOO_ANONYMOUS_TTL_MINUTES', 120)),
        'queue_connection' => env('FEITOO_PROCESSING_QUEUE_CONNECTION', 'database'),
    ],
    'uploads' => [
        'max_files' => min(20, (int) env('FEITOO_MAX_FILES', 20)),
        'max_request_size_kb' => min(30720, (int) env('FEITOO_MAX_REQUEST_SIZE_KB', 30720)),
        // Larger envelopes require a new benchmark before raising these hard ceilings.
        'max_processing_files' => min(250, (int) env('FEITOO_MAX_PROCESSING_FILES', 250)),
        'max_processing_items' => min(4000, (int) env('FEITOO_MAX_PROCESSING_ITEMS', 4000)),
        'max_total_size_kb' => min(40960, (int) env('FEITOO_MAX_TOTAL_SIZE_KB', 40960)),
        'max_file_size_kb' => (int) env('FEITOO_MAX_FILE_SIZE_KB', 10240),
        'max_xml_depth' => 64,
        'max_xml_nodes' => 100000,
        'xml_validation_seconds' => 2,
    ],
    'rate_limits' => [
        'create_per_minute' => (int) env('FEITOO_CREATE_PER_MINUTE', 10),
        'upload_per_minute' => (int) env('FEITOO_UPLOAD_PER_MINUTE', 10),
        'access_per_minute' => (int) env('FEITOO_ACCESS_PER_MINUTE', 60),
    ],
];
