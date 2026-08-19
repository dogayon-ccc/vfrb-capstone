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

    // ── Google Gemini AI (FREE — get key at aistudio.google.com/apikey) ───
    // No credit card. No billing account. Just a Google account.
    // Free tier: 15 requests/minute, 1,500 requests/day
    // Model: gemini-3.6-flash — gemini-1.5-flash was HARD-CODED here before
    // and is fully retired (Google's own docs: "All Gemini 1.0 models and
    // Gemini 1.5 are already shutdown... requests return a 404 error" —
    // confirmed via storage/logs/laravel.log: {"status":404,"key":1}).
    // gemini-2.5-flash was considered but is itself scheduled for shutdown
    // Oct 16 2026 — too close to rely on. gemini-3.6-flash is Google's own
    // current example of a stable production model, no shutdown date
    // announced as of this fix. Now reads from .env (GEMINI_MODEL) with
    // this as the default, so the NEXT retirement is a one-line .env
    // change, not another code deploy.
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