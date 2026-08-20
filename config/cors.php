<?php
// config/cors.php — VFRB Enterprise
// Task EE: Added Railway.app production domains
//
// FIX (2026-08-20): the allowed_origins_patterns regex below was written
// assuming Railway domains look like "xxx.railway.app" (one label before
// the TLD). Real Railway-generated domains actually look like
// "xxx-production.up.railway.app" — an extra ".up." segment the old
// regex didn't account for. Verified directly against the real deployed
// URLs (vfrb-frontend-production.up.railway.app /
// vfrb-capstone-production.up.railway.app) — the old pattern did not
// match either one, which is what caused the CORS block on login.
// Added an optional (\.up)? group so both domain shapes match.
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
        // e.g. https://vfrb-frontend-production.up.railway.app
        // This is an exact-match fallback that works regardless of the
        // regex pattern below — recommended to set this too, not just
        // rely on the wildcard, since it's a simpler/safer exact match.
        env('FRONTEND_URL'),
    ]),

    'allowed_origins_patterns' => [
        // FIX: added optional (\.up)? — Railway's real generated domains
        // are "xxx-production.up.railway.app", not just "xxx.railway.app".
        // The old pattern only matched the second (shorter) shape, which
        // none of this project's actual URLs use — confirmed by testing
        // it directly against both real deployed domains.
        // Remove this line after you have a fixed production URL and are
        // relying solely on FRONTEND_URL above instead.
        '#^https://[a-z0-9\-]+(\.up)?\.railway\.app$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 86400, // 24h preflight cache — reduces OPTIONS requests

    'supports_credentials' => true,

];
