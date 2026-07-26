<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:3000',
        'http://localhost:3001',
        'https://*.vercel.app',
        'https://top-organic-dashboard-p2evr5dtn-teami1.vercel.app',
        env('FRONTEND_URL', ''),
    ],

    'allowed_origins_patterns' => [
        '#^https://top-organic-dashboard.*\.vercel\.app$#',
        '#^http://localhost(:[0-9]+)?$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
