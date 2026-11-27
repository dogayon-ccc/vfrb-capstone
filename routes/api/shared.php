<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProfilePhotoController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

// ══════════════════════════════════════════════════════════════
// AUTHENTICATED — SHARED (any logged-in user)
// ══════════════════════════════════════════════════════════════
// Rate limiting (Aug 23 2026): group-level default via the existing
// ApiThrottle middleware (already used for login/register) rather than
// annotating each route individually — this group covers lightweight,
// frequently-polled endpoints (session check, notification prefs), so
// the limit is generous but no longer absent.
Route::middleware(['auth:sanctum', 'auth.throttle:shared,60'])->group(function () {

    // ── Current user ────────────────────────────────────────────
    // GET /api/user — VerifyEmail.jsx calls this to refresh email_verified_at
    Route::get('/user', [AuthController::class, 'me']);

    // ── Logout ──────────────────────────────────────────────────
    // AdminLayout.jsx + CustomerLayout.jsx both call POST /api/logout
    Route::post('/logout', [AuthController::class, 'logout']);

    // ── Email verification ───────────────────────────────────────
    // VerifyEmail.jsx calls POST /api/email/resend
    Route::post('/email/resend', [AuthController::class, 'resendVerification'])->middleware('throttle:5,1');

    // ── Notification preferences (own row only, any role) ─────────
    // Settings.jsx: GET/PATCH /api/settings/notifications
    Route::get('/settings/notifications', [SettingsController::class, 'notificationShow']);
    Route::patch('/settings/notifications', [SettingsController::class, 'notificationUpdate']);

    // ── Appearance + profile photo (own account only, any role) ───
    Route::get('/settings/appearance', [SettingsController::class, 'appearanceShow']);
    Route::patch('/settings/appearance', [SettingsController::class, 'appearanceUpdate']);
    Route::post('/profile/photo', [ProfilePhotoController::class, 'store'])->middleware('auth.throttle:avatar-upload,10');
    Route::delete('/profile/photo', [ProfilePhotoController::class, 'destroy']);
    // Own sign-in details, open to every admin role (owner and station included) so nobody gets locked out.
    Route::put('/account/login', [UserController::class, 'updateOwnLogin'])->middleware('throttle:10,1');
    Route::put('/account/password', [UserController::class, 'adminUpdatePassword'])->middleware('throttle:10,1');

});
