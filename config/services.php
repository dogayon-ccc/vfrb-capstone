<?php
// config/services.php — VFRB Enterprise (COMPLETE)

return [
    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],
    'ses' => [
        'key'    => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],
    'resend' => [
        'key' => env('RESEND_KEY'),
    ],
    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel'              => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // ── Google OAuth (already configured) ──────────────────────────────────
    'google' => [
        'client_id'     => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect'      => env('GOOGLE_REDIRECT_URI'),
    ],

    // Used for post-login redirects. Read via config() so it survives `php artisan config:cache`.
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),

    // Set at deploy time; shown by GET /api/version.
    'release' => [
        'sha'       => env('APP_RELEASE'),
        'deploy_id' => env('APP_DEPLOY_ID'),
    ],

    // Gemini model is env-driven so a retirement is a .env change, not a deploy.
    // Billing: enable it on the Google AI Studio project that owns the keys and set a project spend cap there.
    // ── Google Gemini AI — 3-key rotation ─────────────────────────────────
    // .env must have GEMINI_KEY_1, GEMINI_KEY_2, GEMINI_KEY_3, GEMINI_KEY_COUNT=3
    // AIController reads config('services.gemini.key_1') etc.
    'gemini' => [
        'key_1'     => env('GEMINI_KEY_1'),          // primary key
        'key_2'     => env('GEMINI_KEY_2'),
        'key_3'     => env('GEMINI_KEY_3'),
        'key_count' => env('GEMINI_KEY_COUNT', 1),
        'model'     => env('GEMINI_MODEL', 'gemini-3.6-flash'),
        // Legacy aliases — keeps old code working during transition
        'key'       => env('GEMINI_KEY_1'),
        'api_key'   => env('GEMINI_KEY_1'),
    ],
];