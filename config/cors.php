<?php
// config/cors.php
// Production origins come from .env: FRONTEND_URL (primary) and CORS_ALLOWED_ORIGINS (extras, comma-separated).
// Auth is Bearer-token based, so no cookies cross origins; supports_credentials stays true for compatibility.

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_merge(
        [
            'http://localhost:5173',
            'http://localhost:3000',
            'http://127.0.0.1:5173',
            env('FRONTEND_URL'),
        ],
        // Extra production origins, comma-separated (e.g. https://vfrb.example.com,https://www.vfrb.example.com)
        array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')))
    ))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 86400, // 24h preflight cache — reduces OPTIONS requests

    'supports_credentials' => true,

];
