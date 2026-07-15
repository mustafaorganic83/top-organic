<?php

/*
|--------------------------------------------------------------------------
| Region Pack — Iraq First
|--------------------------------------------------------------------------
|
| Iraq-first regional defaults for Top Organic. These are configuration,
| not code (architecture README + doc 07): currency, locale, timezone, and
| digit style live here so a future non-Iraq deployment is a config change,
| not a rewrite. Timestamps are stored in UTC and displayed in the region
| timezone (architecture docs 03 & 04).
|
*/

return [

    // Region / country code (ISO 3166-1 alpha-2).
    'region' => env('DEFAULT_REGION', 'IQ'),
    'country' => 'Iraq',

    // Resolved-locale defaults with fallback chain ar-IQ -> ar -> en.
    'locale' => env('DEFAULT_LOCALE', 'ar-IQ'),
    'fallback_chain' => ['ar-IQ', 'ar', 'en'],

    // Locales the platform ships language files / RTL support for.
    'supported_locales' => ['ar', 'en'],

    // Right-to-left locales (Arabic primary).
    'rtl_locales' => ['ar'],

    // Currency: store money as integer minor units (architecture doc 04).
    // IQD has 0 decimals; USD has 2.
    'currency' => [
        'primary' => env('DEFAULT_CURRENCY', 'IQD'),
        'secondary' => env('SECONDARY_CURRENCY', 'USD'),
        'decimals' => [
            'IQD' => 0,
            'USD' => 2,
        ],
    ],

    // Storage timezone is always UTC; this is the display/business timezone.
    'timezone' => env('DISPLAY_TIMEZONE', 'Asia/Baghdad'),

    // Formatting defaults.
    'date_format' => 'dd/MM/yyyy',
    // 'western' (0-9) or 'arabic' (٠-٩) digit shaping — configurable per region.
    'digit_style' => 'western',

];
