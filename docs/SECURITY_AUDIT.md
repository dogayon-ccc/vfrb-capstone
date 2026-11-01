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

## 5. Follow-up audit (read-only, 2026-11-02, branch `wip/oct21-overhaul` at `85dd57c`)
Labels: CONFIRMED weakness = the code path exists today; THEORETICAL = needs an unlikely precondition or isn't proven exploitable. No requests were sent.
Status of §3/§4 since 2026-10-30: Sanctum expiry is now `SANCTUM_EXPIRATION` with a 10080 min default (`config/sanctum.php:25`); localhost CORS is gated (`config/cors.php:15`). Both from commit `edeabda`, not re-audited here.

### 5.1 Customer A vs customer B (every customer route taking an id or a record reference)
| Route(s) | Ownership check | Result |
|---|---|---|
| GET orders/{id} | `OrderController.php:162` `where user_id = Auth::id()` | PASS |
| GET orders/{id}/spec-sheet | `OrderController.php:572,580` | PASS |
| POST orders/{id}/accept, decline, message | `customerProposalOrder` `OrderController.php:1087` | PASS |
| POST ai/recommend-materials (body order_id), orders/{id}/accept-, reject-, select-materials; GET orders/{id}/ai-recommendation | `AIController.php:63,205,271,357,422` + `firstOrFail` | PASS |
| GET orders/{id}/billing, invoices/{id}/document, receipts/{id}/document | `ownsOrder()` `BillingController.php:108,125,136,146` | PASS |
| PUT/DELETE designs/{id}, POST designs/{id}/copy, submit-, withdraw-showcase | `findOwnArchivedDesign()` `DesignController.php:104-110` (own orders only, never templates) | PASS |
| GET messages/{id}, POST messages (body order_id) | `MessageController.php:192,254` | PASS |
| PATCH notifications/{id}/read | `NotificationController.php:67` | PASS |
| GET delivery/{orderId} | `DeliveryController.php:421` | PASS |
| drafts/latest GET/DELETE, POST drafts | No id accepted; keyed on `Auth::id()` (`OrderDraftController.php:35,62-65,92`) | PASS |
| shipping-addresses, billing-profiles, fabric-preferences {id} | per §1 row 3, not re-audited | PASS (earlier) |
| Files (design refs, previews, 3D snapshots, avatars) | Stored with random names (`OrderController.php:333-349`, `ProfilePhotoController.php:28`) on Cloudinary or the `public` disk; URL works without login | THEORETICAL: needs the URL to leak; names are not guessable |

### 5.2 Owner and Station
- Owner: GET/HEAD only, first segment in `AREAS` (`OwnerReadOnly.php:13,27-31`); not messages, users or settings. PASS.
- Station: exact "METHOD uri" allow-list (`StationScope.php:14-43`), stage check on 3 writes (`:46-50,74-78`), money keys stripped from JSON (`:53-57,81-84`). PASS.
- VERIFIED 2026-11-02: `POST api/admin/output-logs` also requires the order to be at that stage, checked under a row lock (`ProductionStageService.php:160-171`); a mismatch returns 422 and writes nothing (test `StationScopeTest::test_station_cannot_log_its_stage_on_an_order_at_another_stage`).
- FIXED 2026-11-02: QC checklists are now rejected with 422 unless the order is at `qc`. The check, the insert and `qc_passed_at` run in one transaction with the order row locked, matching the output-log path (`QCChecklistController.php`; test `StationScopeTest::test_qc_is_rejected_unless_the_order_is_at_qc`). The original finding, kept for the record: `POST api/admin/orders/{orderId}/qc` (`QCChecklistController.php:92-152`) did not check that the order is at `qc`. The QC station or a manager can submit a passing checklist while the order is still at, say, cutting. `qcBlocked()` (`ProductionStageService.php:646-654`) reads the latest checklist, so when the order later reaches QC it can advance to pressing without a QC check done at that stage. It also sets `qc_passed_at` early (`QCChecklistController.php:148-151`).
- Defects (`POST orders/{id}/defects`) are limited to pieces the same user sewed (`ProductionStageService.php:550-557`) and are not tied to the order's current stage. By design: defects can be found after sewing.
- THEORETICAL (by design): a station can read every order and message thread and post messages to any customer (`StationScope.php:20-37`). That exposes customer PII to every station account.
- THEORETICAL: money stripping works only on JSON keys (`StationScope.php:82`); any new money field outside `MONEY_KEYS` would leak. No such field found in this pass.

### 5.3 Uploads
| Upload | Rule | Storage path |
|---|---|---|
| Order design_ref_file | jpg, jpeg, png, pdf, ≤ 5120 KB (`UploadRules.php:9-15`) | `design-refs/` random name |
| design_preview_file, design_3d_snapshot_file | png, ≤ 5120 KB (`OrderController.php:253-254`) | `design-previews/` |
| Profile photo | image, jpg/jpeg/png/webp, 64-4000 px, `MAX_KB` (`ProfilePhotoController.php:22-24`) | `avatars/{user_id}/` |
| Company logo | image, jpg/jpeg/png/webp, ≤ 2048 KB (`SettingsController.php:133`) | `company-logo/` |
| Draft preview | base64 string ≤ 300000 chars, stored in DB (`OrderDraftController.php:58`) | `order_drafts.preview_dataurl` |
Laravel `mimes` checks the detected content type, not only the extension. No SVG or HTML is accepted. PASS.
- THEORETICAL: PDFs are served from a public URL with no malware scan; a hostile PDF only reaches staff who open it.
- CONFIRMED weakness (low): if `CLOUDINARY_CLOUD_NAME` is unset, uploads fall back to the `public` disk (`UploadDisk.php:10-13`), which is ephemeral on Vercel, so files are lost.

### 5.4 Logout and token cleanup
- Logout deletes the current token only (`AuthController.php:248`). PASS for that device.
- FIXED 2026-11-02: password reset now deletes every token of the user (`AuthController.php` `resetPassword`; test `AuthHardeningTest::test_password_reset_revokes_every_token`).
- FIXED 2026-11-02: a logged-in password change (`customerUpdatePassword`, `PUT admin/profile/password`, both via `UserController::changeOwnPassword`) now deletes every other token and keeps the current session (test `AuthHardeningTest::test_password_change_keeps_this_session_and_revokes_the_others`).
- Deactivation already deletes all of the user's tokens (`UserController.php:309-314`; test `SecurityGateTest.php:273`), and `RoleMiddleware.php:37-39` blocks deactivated accounts on role routes. Correction: an earlier version of this section said deactivated tokens survive on the shared group and Studio AI routes. That was wrong; the tokens are deleted on deactivation.

### 5.5 Rate limits
- Login and admin login: `throttle:10,1` (`routes/api.php:82,88`); forgot/reset 5/1 (`:91-92`). PASS.
- THEORETICAL: these limits are per IP with no per-account lockout. A distributed guess is not blocked, and `trustProxies('*')` (`bootstrap/app.php:64`) lets a client set its own IP via X-Forwarded-For if the origin is reachable without the proxy (whether Vercel overwrites the header is UNVERIFIED).
- AI Studio routes: 30/1 and 20/1 per user (`routes/api.php:95,97`). PASS.
- FIXED 2026-11-02: `POST customer/ai/recommend-materials` now has `throttle:10,1` per user on top of the customer bucket (`routes/api.php:172`; test `AuthHardeningTest::test_recommend_materials_is_limited_to_ten_per_minute`).
- The limiter uses the cache store (`CACHE_STORE=database` in `vercel.json`), so limits hold across serverless instances. PASS.

### 5.6 New fixes (append to §4; no code changed in this audit)
| Rank | Fix | Size |
|---|---|---|
| 11 | Revoke all tokens on password reset and password change (keeps the current session): DONE 2026-11-02 (deactivation already did) | S |
| 12 | Add `throttle:10,1` (per user) to `customer/ai/recommend-materials`: DONE 2026-11-02 | S |
| 13 | Dropped: deactivation already deletes tokens | — |
| 14 | Confirm OutputLogController rejects an order that is not at the station's stage: VERIFIED 2026-11-02 (test added) | S |
| 15 | Reject a QC checklist unless the order is at `qc` (422), so an early pass cannot release QC later: DONE 2026-11-02 | S |
