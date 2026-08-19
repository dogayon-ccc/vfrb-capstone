<?php
// config/cors.php — VFRB Enterprise
// Task EE: Added Railway.app production domains
//
// This file works for BOTH local dev and production.
// The allowed_origins array accepts env-based entries so you only need
// to set FRONTEND_URL in Railway environment variables.
//
// Local dev:   FRONTEND_URL=http://localhost:5173  (already in .env)
// Production:  FRONTEND_URL=https://<subdomain>.railway.app
//
// DEPLOY: replace C:/laragon/www/vfrb-capstone/config/cors.php

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter([
        // Local development
        'http://localhost:5173',
        'http://localhost:3000',
        'http://127.0.0.1:5173',
        // Production — set FRONTEND_URL in Railway Variables tab
        // e.g. https://vfrb-client.railway.app
        env('FRONTEND_URL'),
        // Fallback: allow any .railway.app subdomain if needed
        // (remove before final handover to client domain)
    ]),

    'allowed_origins_patterns' => [
        // Allows any *.railway.app subdomain during development/testing.
        // Remove this line after you have a fixed production URL.
        '#^https://[a-z0-9\-]+\.railway\.app$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 86400, // 24h preflight cache — reduces OPTIONS requests

    'supports_credentials' => true,

];