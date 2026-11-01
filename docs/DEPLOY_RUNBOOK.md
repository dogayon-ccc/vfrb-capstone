# VFRB backend deploy runbook (2026-11-01)

Read-only audit of branch `wip/oct21-overhaul` at `85dd57c`. Statuses: CODE-VERIFIED (read in this repo), UNVERIFIED (not checked here; for Vercel/Supabase/Cloudflare dashboards, check docs), BLOCKED. Nothing here is DEPLOYMENT-VERIFIED.

## 1. Environment variables (names only)

Hosting: Vercel PHP function (`vercel.json`, `api/index.php`, runtime `vercel-php@0.8.0`, region `sin1`, maxDuration 60). CODE-VERIFIED.

Set in `vercel.json` `env` (committed, not secret): APP_ENV, APP_DEBUG, APP_CONFIG_CACHE, APP_EVENTS_CACHE, APP_PACKAGES_CACHE, APP_ROUTES_CACHE, APP_SERVICES_CACHE, VIEW_COMPILED_PATH, LOG_CHANNEL, SESSION_DRIVER, CACHE_STORE, QUEUE_CONNECTION, DB_CONNECTION, DB_SSLMODE.

Must be set in the Vercel project (dashboard, UNVERIFIED which are present):

| Name | Required | Config key | Custom-domain change |
|---|---|---|---|
| APP_KEY | yes | app.key | never change |
| APP_URL | yes | app.url, filesystems.disks.public.url, mail EHLO | set to `https://api.<domain>` |
| FRONTEND_URL | yes | app.frontend_url, services.frontend_url, cors origin #1 | set to `https://app.<domain>` |
| CORS_ALLOWED_ORIGINS | optional | cors.allowed_origins (comma list) | add old vercel.app origin during cutover |
| CORS_ALLOW_LOCALHOST | optional (must be off) | cors | none; keep false/unset |
| SANCTUM_EXPIRATION | optional (default 10080) | sanctum.expiration | none |
| SANCTUM_STATEFUL_DOMAINS | optional | sanctum.stateful | not used by token auth (see §4) |
| SESSION_DOMAIN | optional | session.domain | leave unset (no cookie auth) |
| DB_URL or DB_HOST/DB_PORT/DB_DATABASE/DB_USERNAME/DB_PASSWORD | yes | database.connections.pgsql | none |
| DB_PERSISTENT, DB_EMULATE_PREPARES | optional | database.pgsql options | none |
| GEMINI_KEY_1 (+ _2, _3), GEMINI_KEY_COUNT | yes for AI | services.gemini.* | none |
| GEMINI_MODEL, GEMINI_FALLBACK_MODEL, GEMINI_TIMEOUT, GEMINI_RETRIES, GEMINI_BUDGET | optional | services.gemini.* | none |
| GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET, GOOGLE_REDIRECT_URI | yes if Google login | services.google | redirect URI → `https://api.<domain>/...` callback; also update Google console (UNVERIFIED) |
| MAIL_MAILER, MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD, MAIL_SCHEME, MAIL_FROM_ADDRESS, MAIL_FROM_NAME (or RESEND_KEY / POSTMARK_TOKEN) | yes for reset emails | mail.*, services.* | from address on `<domain>` needs SPF/DKIM (UNVERIFIED) |
| CLOUDINARY_KEY, CLOUDINARY_SECRET, CLOUDINARY_CLOUD_NAME, CLOUDINARY_URL, CLOUDINARY_SECURE, CLOUDINARY_PREFIX | yes for uploads on Vercel | filesystems.disks.cloudinary | none |
| FILESYSTEM_DISK | optional | filesystems.default | none |
| APP_RELEASE, APP_DEPLOY_ID | optional | services.release (GET /api/version) | set per deploy |
| VFRB_LEAD_DAYS_PER_100 | optional | vfrb.lead_days_per_100 | none |
| LOG_LEVEL | optional | logging | `error` or `warning` suggested |

Full list of names read by `config/*.php` (env() calls; none outside config/, CODE-VERIFIED) also includes framework defaults not used in this stack (AWS_*, REDIS_*, MEMCACHED_*, SQS_*, BEANSTALKD_*, DYNAMODB_*, PAPERTRAIL_*, LOG_SLACK_*, SLACK_*).

Frontend (`C:\Users\Dave\Desktop\threejs`, read only, CODE-VERIFIED): VITE_API_URL (`src/App.jsx:14`, `src/utils/fileUrl.js`, `src/pages/auth/Login.jsx:81`) is the host root with no `/api` suffix → `https://api.<domain>`; VITE_MODEL_BASE_URL (`src/lib/modelUrl.js`, `AIMaterials.jsx:318`); VITE_GOOGLE_LOGIN (`Login.jsx:20`, `AuthSkeleton.jsx:24`). Frontend `vercel.json` has only an SPA rewrite and a main-only build gate; no domain values.

## 2. Database and migrations

| Migration | vfrb_demo | Live (Supabase) | Source |
|---|---|---|---|
| 2026_10_29_000001_add_delivery_method_to_orders | UNVERIFIED (not queried) | Ran, batch 50 | SYSTEM_RECAP §4, memory |
| 2026_10_30_000001_add_hot_path_indexes | UNVERIFIED | Ran, batch 51 (2026-10-31) | SYSTEM_RECAP §4 |
| 2026_10_30_000002_add_tracking_updated_index | UNVERIFIED | Ran, batch 52 (2026-10-31) | SYSTEM_RECAP §4 |
| 2026_10_12_000001_remove_estimate_columns_and_usage_rates | — | FORBIDDEN to run; already Ran batch 38 | RELEASE_AUDIT §5 |

CONFLICT: the task brief says these three are "not on live"; repo docs say all are Ran. Re-run `php artisan migrate:status` on live (the user runs it) before deciding. UNVERIFIED either way here.

Safe order if any are pending:
1. Supabase backup / PITR snapshot (dashboard, UNVERIFIED, check docs). Record the time.
2. `migrate:status` on live; confirm 2026_10_12 shows Ran.
3. One file at a time with `--path`: 2026_10_29_000001 → 2026_10_30_000001 → 2026_10_30_000002. Never plain `migrate`.
4. `migrate:status` again; deploy code only after.

Duplicate index on `public.orders` (idx_orders_design_id vs orders_design_id_foreign): no repo migration creates either name; `orders` has no `Schema::create` here (base table predates the repo migrations). Origin UNVERIFIED (likely manual SQL or Supabase advisor). Do not drop orders_design_id_foreign (FK-backed). If anything, review idx_orders_design_id later; no change in this release.

## 3. Postgres risks (MySQL-specific code)

| File:line | Issue | Status |
|---|---|---|
| database/migrations/2026_08_25_000001_fix_order_production_tracking_stage_enum.php:58,89 | `ALTER TABLE ... MODIFY COLUMN`, no driver guard | MySQL-only; already ran or skipped on live (UNVERIFIED) — fails on fresh PG |
| database/migrations/2026_09_07_000001_make_estimated_range_nullable_on_material_recommendations.php:46-60 | backticks + MODIFY, no guard | same |
| database/migrations/2026_10_05_000001_add_b2b_client_types_to_users.php:11,17 | `MODIFY ... ENUM(...)`, no guard | same |
| app/Http/Controllers/Api/MessageController.php:47,172 | `is_read = 0` in raw SQL; fails if PG column is boolean | UNVERIFIED column type (comment line 7 says tinyint) |
| app/Http/Controllers/Api/MessageController.php:69 | `where('is_read', 0)` bound int | same risk |
| app/Http/Controllers/Api/MessageController.php:172 | `$userId` concatenated into SQL (int, low risk) | CODE-VERIFIED |
| app/Support/Sql.php:16-44 | DATE_FORMAT / JSON_OBJECT / CAST UNSIGNED / LIKE have PG branches | CODE-VERIFIED safe |
| 2026_10_14, 2026_10_16, 2026_10_17_000003, 2026_10_22_000002 | driver-guarded | CODE-VERIFIED |

Tests run on MySQL only; PG behaviour of all queries is UNVERIFIED.

## 4. CORS, Sanctum, auth mode, URLs

- Auth is Bearer token: `AuthController.php:390-395` `createToken('vfrb-token')`; `bootstrap/app.php:66-69` `statefulApi()` disabled. Cookies/sessions are not used for API auth, so SESSION_DOMAIN and SANCTUM_STATEFUL_DOMAINS do not need changing. CODE-VERIFIED.
- CORS (`config/cors.php:12-20`): origins = FRONTEND_URL + CORS_ALLOWED_ORIGINS (+ localhost only if CORS_ALLOW_LOCALHOST). Moving to `app.<domain>` breaks every browser call until FRONTEND_URL is updated and config cache rebuilt (redeploy). `supports_credentials=true` requires exact origins (no `*`). CODE-VERIFIED.
- Google OAuth: callback redirects to `services.frontend_url` + `/auth/google/complete?token=` (`AuthController.php:163,224`); verify-email uses `app.frontend_url` (`:346`). Wrong FRONTEND_URL = login lands on old host. GOOGLE_REDIRECT_URI must match the Google console (UNVERIFIED).
- Storage URLs: `public` disk url = APP_URL + `/storage` (`config/filesystems.php:44`); `Material::image_path` uses `asset()` (`app/Models/Material.php:90`) → follows APP_URL. Design previews go to `designRefDisk()` (`OrderController.php:335-349`; same logic as `DesignController.php:19-26` and `app/Support/UploadDisk.php:8-14`): Cloudinary when CLOUDINARY_CLOUD_NAME is set, else `public`. URLs are built per request (`app/Support/FileUrl.php:22-27`), so Cloudinary images keep working after a domain change (CODE-VERIFIED). If CLOUDINARY_CLOUD_NAME is missing on Vercel, uploads hit the ephemeral `public` disk and vanish; whether it is set: UNVERIFIED.
- `trustProxies(at: '*')` (`bootstrap/app.php:64`) fine behind Cloudflare/Vercel. CODE-VERIFIED.

## 5. Errors and logging

- `vercel.json` sets APP_DEBUG=false, APP_ENV=production, LOG_CHANNEL=stderr. `config/app.php:13` defaults debug to false. 5xx message is fixed text unless debug (`bootstrap/app.php:120-134`). CODE-VERIFIED.
- PHP `display_errors`: no php.ini / .user.ini in repo; controlled by the vercel-php runtime. UNVERIFIED — check a forced 500 returns JSON only.
- A dashboard env APP_DEBUG may override vercel.json; confirm it is false. UNVERIFIED.

## 6. Request body size (~200 KB design saves)

- `OrderDraftController.php:58` `preview_dataurl` max 300000 chars (~220 KB PNG); order `design_preview_file` max 5120 KB (`OrderController.php:253`). CODE-VERIFIED.
- Vercel function request body limit (documented ~4.5 MB) and vercel-php `post_max_size`: UNVERIFIED, check docs. 200 KB is well under typical limits.
- Local demo server: use the absolute router path (CLAUDE.md) or POSTs over 16 KB drop.
- Cloudflare proxy (orange cloud) upload limit 100 MB on free plan: UNVERIFIED, check docs.

## 7. Gemini

- Keys: `config/services.php:45-48` (GEMINI_KEY_1..3, GEMINI_KEY_COUNT), read in `app/Services/GeminiClient.php:37-39`. CODE-VERIFIED.
- Model: already env-driven — GEMINI_MODEL (`config/services.php:49`, default `gemini-3.8-flash`) and GEMINI_FALLBACK_MODEL (`:50`). Note `GeminiClient.php:17` second-argument default is `gemini-3.6-flash`, which differs from the config default (harmless; config wins). No code change needed.
- Guard: prompt forbids quantity/length/yardage/price (`AIController.php:812`, `:551`); customer output is whitelisted to category/unit + catalog facts via `array_intersect_key` (`AIController.php:433-446`); Studio edits are schema-validated enums (`app/Services/DesignEditValidator.php:67-73`). A server-side scan rejecting numbers/prices in free-text `ai_note` was not found here: UNVERIFIED.

## 8. Deploy steps, smoke, rollback

1. Backup: Supabase snapshot (UNVERIFIED); note current Vercel production deployment IDs (backend + frontend).
2. Migrations per §2 only if `migrate:status` shows pending.
3. DNS (Cloudflare): `api` CNAME → Vercel backend, `app` CNAME → Vercel frontend; add both domains in Vercel. Proxy mode and SSL "Full (strict)" vs DNS-only: UNVERIFIED, check docs.
4. SSL: wait for Vercel certificates on both hosts (UNVERIFIED).
5. Env: backend APP_URL, FRONTEND_URL, CORS_ALLOWED_ORIGINS (old origin), GOOGLE_REDIRECT_URI, MAIL_FROM_ADDRESS; frontend VITE_API_URL (Vite inlines it at build, so the frontend must be rebuilt).
6. Redeploy backend first, then frontend (frontend needs new endpoints; SYSTEM_RECAP §10).
7. Smoke: `GET /api/version`; login customer, manager, station, owner; owner POST → 403; station write on other stage → 403; Studio save (draft ~200 KB); file upload (≤5 MB png/pdf); customer submits order; manager sees it in admin orders; preview image loads from the new host; Google login; password-reset email link host.

Rollback triggers: login fails for any role, CORS errors in browser, 5xx on order create or draft save, previews 404, AI returns quantities/prices.
Rollback: Vercel "promote" previous deployment for backend and frontend (UNVERIFIED, check docs); restore old FRONTEND_URL/APP_URL/VITE_API_URL; point DNS back or leave `*.vercel.app` live; DB restore only if a migration damaged data (all listed migrations are additive, so `migrate:rollback --path` of one file is preferred over a full restore).

## Open risks

- Live migration state conflicts between task brief and repo docs (§2).
- Duplicate orders index origin unknown (§2).
- PG `is_read` type and unguarded MySQL migrations (§3).
- CLOUDINARY_CLOUD_NAME on Vercel (§4). Display_errors and body limits on Vercel (§5, §6).
