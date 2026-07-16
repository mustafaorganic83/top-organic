<?php

return [
    'pagination' => [
        'default' => 25,
        'maximum' => 100,
    ],
    'pos_device_types' => ['pos'],
    'edge_print_device_types' => ['pos', 'desktop'],
    'kds' => [
        'default_station_code' => 'DEFAULT',
    ],
    'printing' => [
        'protocol_version' => 1,
        'max_attempts' => 5,
        'retry_seconds' => 30,
    ],
    'sync' => [
        'schema_version' => 1,
        'push_batch_limit' => 200,
        'pull_page_limit' => 200,
        'pull_page_default' => 100,
        'idempotency_retention_days' => 30,
        'tombstone_retention_days' => 90,
        'outbox' => [
            'claim_limit' => 100,
            'claim_timeout_seconds' => 300,
            'max_attempts' => 8,
            'retry_seconds' => 30,
            'retry_backoff_cap_seconds' => 3600,
        ],
    ],
];
