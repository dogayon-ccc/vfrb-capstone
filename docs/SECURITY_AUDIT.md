# VFRB Security Audit (M14, read-only, 2026-10-30)
Scope: backend `vfrb-capstone` + frontend `threejs/src`. Graded against the constitution checks, since the Master Plan file was not found.
No live DB queried. "Confirmed" means a cited code path; nothing below was exploited with a real request.

## 1. Constitution checks
| # | Check | Result | Evidence |
|---|---|---|---|
| 1 | Customer ownership (designs/orders/messages/uploads) | PASS | `OrderController.php:162,1087`, `DesignController.php:107-108`, `MessageController.php:192`, `BillingController.php:146`, `AIController.php:205,271,357,422` |
| 2 | Role boundaries | PASS (code) | `routes/api.php:147` role:customer; `:289` staff group + `owner.readonly` + `station`; `StationScope.php:76`; `OwnerReadOnly.php:28`; `RoleMiddleware.php:39-61` |
| 3 | IDOR on customer `{id}` routes | PASS | All 24 customer `{id}` handlers scope by `user_id = Auth::id()` (incl. `ShippingAddressController.php:43`, `BillingProfileController.php:43`, `CustomerFabricPreferenceController.php:45`, `NotificationController.php:67`) |
| 3b | IDOR on admin `{id}` routes | UNVERIFIED | Staff see all orders by design; station per-record limits only on writes (`StationScope.php:76`) |
| 4 | Logout cleanup | PASS | Token deleted `AuthController.php:248`; frontend `utils/session.js:21-34` clears token, user, sessionStorage, queue, API cache |
| 5 | Session leakage on shared browser | PASS | `session.js:40` wipes on login; leftover `ds_*` localStorage keys are UI prefs only (`ToolDrawer.jsx:25`) |
| 6 | Upload handling | PASS / UNVERIFIED | Rules: `UploadRules.php:9-11` (jpg,jpeg,png,pdf ≤5MB), `OrderController.php:252-254`, `ProfilePhotoController.php:22`, `SettingsController.php:133`. Stored on Cloudinary with public URLs (`UploadDisk.php:11`), so access control on files is UNVERIFIED |
| 7 | Unsafe rendering | PASS | No `dangerouslySetInnerHTML`/`innerHTML=` in `threejs/src`; no `{!!` in `resources/views`. Mailables outside views UNVERIFIED |
| 8 | Customer payloads (no prices/stages/formulas) | PASS (sampled) | `OrderController.php:195` stages hidden; `BillingController.php:111-115` amount-free `only()`. A full sweep of all customer responses is UNVERIFIED |
| 9 | Login throttle / reset / request-access | PARTIAL FAIL | Throttles `routes/api.php:82,85,88,91,92`. Reset leaks whether an account exists: success message `AuthController.php:261` differs from fallback `:265`. Request-access answers duplicates the same way (`CustomerContactController.php` `$reply`), but has no captcha |
| 10 | Secrets/config | PARTIAL FAIL | `.env` gitignored (`.gitignore:9`), not tracked. Local `.env:2,4` has `APP_ENV=local`, `APP_DEBUG=true` and points at production DB. Deployed values UNVERIFIED |

## 2. Routes without auth middleware (193 routes scanned)
`GET /`, `GET api/version`, `GET sanctum/csrf-cookie`, `POST api/login`, `POST api/admin/login`,
`POST api/password/forgot`, `POST api/password/reset`, `POST api/request-access`,
`GET auth/google/redirect`, `GET auth/google/callback`, `GET api/email/verify/{id}/{hash}`.
All are meant to be public. Note: the verify route checks only the sha1 hash (`AuthController.php:353`), with no `hasValidSignature` found.

## 3. Platform settings
- Sanctum: `config/sanctum.php:24` `'expiration' => null`, so tokens in localStorage never expire. FAIL.
- CORS: explicit list `config/cors.php:12-21`, `supports_credentials` true. Localhost origins are also allowed in production. LOW.
- Headers: `SecurityHeaders.php:28-32` nosniff, SAMEORIGIN, Referrer, Permissions; CSP `default-src 'none'` on api/* (`:37`). No HSTS (Cloudflare may add it: UNVERIFIED).
- Proxies: `bootstrap/app.php:64` `trustProxies('*')`. Origin must stay behind Cloudflare (UNVERIFIED).
- Password reset tokens expire in 60 min (`config/auth.php:97`).
- RLS: only 6 tables in `2026_10_22_000002_enable_rls_on_flagged_tables.php:10-17` (invoices, payment_receipts, audit_logs, customer_contacts, client_material_ledger, recommendation_guidance). No migration covers RLS on any other table. Whether it ran on Supabase is UNVERIFIED.

## 4. Fixes by risk
| Rank | Fix | Size |
|---|---|---|
| 1 | Set Sanctum `expiration` (e.g. 7 days) + prune expired tokens | S |
| 2 | Confirm production `APP_DEBUG=false`, `APP_ENV=production`; stop using prod DB in local `.env` | S |
| 3 | Check RLS status of every public Supabase table (read-only) and extend the migration to the rest | M |
| 4 | Sweep every customer JSON response for price/stage/formula keys; add a test per endpoint | M |
| 5 | Serve design-ref uploads via signed/authenticated URLs instead of public Cloudinary links | M |
| 6 | Same reply for forgot-password success and failure (`AuthController.php:261`) | S |
| 7 | Add `signed` check to email verify route | S |
| 8 | Add HSTS header; drop localhost CORS origins when `APP_ENV=production` | S |
| 9 | Audit admin `{id}` routes for station/owner record scope | M |
| 10 | Captcha or honeypot on request-access | S |
