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

// ── Email verification (Aug 25 2026 — the confirmed bug fix) ──────────────
// This route name ('verification.verify') is exactly what
// CustomVerifyEmailNotification::buildVerificationUrl() has been building
// signed URLs against all along — it just never existed as an actual
// route, confirmed by the RouteNotFoundException thrown when testing the
// notification directly. The 'signed' middleware validates the URL itself
// (unexpired, untampered) before AuthController::verifyEmail() ever runs.
Route::get('/api/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
    ->middleware('signed')
    ->name('verification.verify');
