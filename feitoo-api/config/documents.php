<?php

return [
    'driver' => env('DOCUMENT_CONVERSION_DRIVER', 'libreoffice'),
    'binary' => env('LIBREOFFICE_BINARY', 'soffice'),
    // Production fails closed without OS isolation. 'none' is local/testing only.
    'sandbox' => env('DOCUMENT_CONVERSION_SANDBOX', 'bubblewrap'),
    'bubblewrap_binary' => env('DOCUMENT_BWRAP_BINARY', 'bwrap'),
    'prlimit_binary' => env('DOCUMENT_PRLIMIT_BINARY', 'prlimit'),
    'timeout' => max(1, min(120, (int) env('DOCX_TO_PDF_TIMEOUT', 60))),
    'max_file_size_kb' => max(1, (int) env('DOCX_MAX_FILE_SIZE_MB', 10)) * 1024,
    'max_files' => max(1, min(20, (int) env('DOCX_MAX_FILES_PER_PROCESSING', 20))),
    'max_total_size_kb' => max(1, (int) env('DOCX_MAX_TOTAL_SIZE_MB', 40)) * 1024,
    'max_uncompressed_bytes' => max(1, (int) env('DOCX_MAX_UNCOMPRESSED_SIZE_MB', 80)) * 1048576,
    'max_entries' => max(1, (int) env('DOCX_MAX_ZIP_ENTRIES', 2048)),
    'max_ratio' => max(1, (int) env('DOCX_MAX_COMPRESSION_RATIO', 100)),
    'max_xml_bytes' => 10 * 1048576,
    'validation_seconds' => 5,
    'max_pdf_bytes' => max(1, (int) env('DOCX_MAX_PDF_SIZE_MB', 25)) * 1048576,
    'max_total_pdf_bytes' => max(1, (int) env('DOCX_MAX_TOTAL_PDF_SIZE_MB', 80)) * 1048576,
    'memory_bytes' => max(256, (int) env('DOCX_CONVERSION_MEMORY_MB', 1024)) * 1048576,
    'limits' => [
        'free' => ['per_processing' => max(0, (int) env('DOCX_FREE_PER_PROCESSING', 3)), 'daily' => max(0, (int) env('DOCX_FREE_DAILY', 6))],
        'pro' => ['per_processing' => max(0, (int) env('DOCX_PRO_PER_PROCESSING', 20)), 'daily' => max(0, (int) env('DOCX_PRO_DAILY', 100))],
    ],
];
