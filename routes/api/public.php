<?php

use App\Http\Controllers\Api\AIController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AutomationController;
use App\Http\Controllers\Api\CustomerContactController;
use App\Http\Controllers\Api\VersionController;
use Illuminate\Support\Facades\Route;

// ══════════════════════════════════════════════════════════════
// PUBLIC ROUTES (no auth)
// ══════════════════════════════════════════════════════════════

// ── Deploy verification (NEW Aug 30 2026) ───────────────────────
// GET /api/version — confirms what's actually live: commit hash,
// deploy time, PHP/Laravel version, real DB connectivity check.
// No auth on purpose — a validator shouldn't need to log in just to
// confirm the system is up and talking to its database.
Route::get('/version', [VersionController::class, 'show']);

// ── Cron (serverless has no schedule:run) — CRON_SECRET bearer, runs the due daily jobs ──
Route::match(['get', 'post'], '/cron/run', [AutomationController::class, 'cron'])->middleware('throttle:10,1');

// ── Customer auth ─────────────────────────────────────────────
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
// Self-registration is disabled: an Administrator creates customer accounts (POST /api/admin/users).
// Public "Request access": records a customer_contacts row and notifies managers; never creates a user.
Route::post('/request-access', [CustomerContactController::class, 'requestAccess'])->middleware('throttle:5,1');

// ── Admin auth (alias so both /api/admin/login and /api/login work) ──
Route::post('/admin/login', [AuthController::class, 'adminLogin'])->middleware('throttle:10,1');

// ── Password reset ────────────────────────────────────────────
Route::post('/password/forgot', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
Route::post('/password/reset', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1');

// ── AI Design Studio prompt (Design Studio requires a customer login) ─
Route::post('/ai/describe-design', [AIController::class, 'describeDesign'])->middleware(['auth:sanctum', 'throttle:30,1']);
// Studio edit-by-instruction: strict operations on the current template's real zones (AIController::designEdits).
Route::post('/ai/design-edits', [AIController::class, 'designEdits'])->middleware(['auth:sanctum', 'throttle:20,1']);

// ── Google OAuth ──────────────────────────────────────────────
// Real routes now live in routes/web.php (Aug 23 2026) — GET
// /auth/google/redirect and /auth/google/callback. They're full-page
// browser navigations, not axios calls, so they need the 'web' session
// middleware, not the stateless 'api' group this file uses.
