<?php

return [
    'authentication' => [
        'require_authorized_device' => (bool) env('IDENTITY_REQUIRE_AUTHORIZED_DEVICE', false),
        'access_audience' => env('IDENTITY_ACCESS_AUDIENCE', 'top-organic-api'),
        'offline_audience' => env('IDENTITY_OFFLINE_AUDIENCE', 'top-organic-offline-login'),
    ],

    'password' => [
        'require_letters' => (bool) env('IDENTITY_PASSWORD_REQUIRE_LETTERS', true),
        'require_numbers' => (bool) env('IDENTITY_PASSWORD_REQUIRE_NUMBERS', true),
        'require_symbols' => (bool) env('IDENTITY_PASSWORD_REQUIRE_SYMBOLS', false),
    ],

    'mfa' => [
        'challenge_ttl_minutes' => (int) env('IDENTITY_MFA_CHALLENGE_TTL', 5),
        'max_attempts' => (int) env('IDENTITY_MFA_MAX_ATTEMPTS', 5),
        'verifiers' => [],
    ],
];
