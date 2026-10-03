<?php

declare(strict_types=1);

return [
    'enabled' => env('MONITOR_ENABLED', true),
    'storage' => [
        'path' => storage_path('logs/monitor'),
        'retention_days' => (int) env('MONITOR_RETENTION_DAYS', 7),
    ],
    'request' => [
        'enabled' => env('MONITOR_REQUESTS', true),
        'slow_ms' => (int) env('MONITOR_SLOW_REQUEST_MS', 1000),
        'capture_user' => env('MONITOR_CAPTURE_USER', true),
        'capture_body' => env('MONITOR_CAPTURE_REQUEST_BODY', false),
        'capture_source' => env('MONITOR_CAPTURE_SOURCE', false),
        'trace_header' => env('MONITOR_TRACE_HEADER', true),
    ],
    'queries' => [
        'enabled' => env('MONITOR_QUERIES', false),
        'slow_enabled' => env('MONITOR_SLOW_QUERIES', true),
        'slow_ms' => (int) env('MONITOR_SLOW_QUERY_MS', 500),
        // Positional bindings cannot reliably be mapped to sensitive column names.
        'capture_bindings' => false,
    ],
    'n_plus_one' => [
        'enabled' => env('MONITOR_NPLUS1', false),
        'threshold' => (int) env('MONITOR_NPLUS1_THRESHOLD', 20),
        'max_fingerprints' => 1000,
    ],
    'queue' => ['enabled' => env('MONITOR_QUEUE', true)],
    'breadcrumbs' => ['max' => (int) env('MONITOR_MAX_BREADCRUMBS', 100)],
    'privacy' => [
        'masked_fields' => [
            'password', 'password_confirmation', 'token', 'access_token', 'refresh_token',
            'authorization', 'cookie', 'set_cookie', 'secret', 'api_key', 'client_secret',
            'session', 'session_id', 'database_password', 'credit_card', 'card_number', 'cvv',
        ],
        'max_depth' => 8,
        'max_items' => 100,
        'max_total_items' => 1000,
        'max_string_length' => 2048,
    ],
    'exceptions' => ['stack_frames' => 15],
    'console' => [
        'window' => '24h',
        'max_events' => 100000,
        'max_groups' => 1000,
        'max_line_bytes' => 262144,
    ],
];
