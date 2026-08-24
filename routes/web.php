<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// ── Google OAuth — Customer Sign-In (Aug 23 2026) ──────────────────────────
// Real implementation. These live here in web.php (not api.php) because
// they're full-page browser navigations, not axios/XHR calls — Socialite's
// default (non-stateless) flow needs the 'web' middleware group's session
// handling for its CSRF-protecting state parameter. Customer-only
// enforcement lives inside AuthController::googleCallback(), not here.
Route::get('/auth/google/redirect', [AuthController::class, 'googleRedirect']);
Route::get('/auth/google/callback', [AuthController::class, 'googleCallback']);
