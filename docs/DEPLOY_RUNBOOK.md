# VFRB backend deploy runbook (2026-11-02)

Read-only audit of branch `wip/oct21-overhaul` at `0e33808`. Statuses: CODE-VERIFIED (read in this repo), UNVERIFIED (not checked here; for Vercel/Supabase/Cloudflare dashboards, check docs), BLOCKED. Nothing here is DEPLOYMENT-VERIFIED.

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
| CRON_SECRET | yes for automations | services.cron.secret | none; unset = `/api/cron/run` always 401 (§9) |
| APP_TIMEZONE | optional (default UTC) | app.timezone | none; decides the cron UTC times in §9 |

Release checklist (tick in the Vercel dashboard; names only, never paste values here):
- [ ] APP_DEBUG=false (also pinned in `vercel.json` env; with true, 5xx JSON exposes exception text)
- [ ] CRON_SECRET set, random, ≥16 chars (Vercel's recommendation)
- [ ] GEMINI_MODEL=gemini-3.8-flash (VERIFIED: listed as stable on ai.google.dev/gemini-api/docs/models; gemini-3.6-flash also stable)
- [ ] GEMINI_KEY_1 (+ _2, _3) and GEMINI_KEY_COUNT matching the number of keys set
- [ ] CACHE_STORE=database (pinned in `vercel.json`; locks need `cache_locks`, query below)
- [ ] FRONTEND_URL=`https://app.<domain>` (no trailing slash)
- [ ] CLOUDINARY_CLOUD_NAME set (with CLOUDINARY_KEY/SECRET or CLOUDINARY_URL)
- [ ] APP_TIMEZONE: check the CURRENT live value first; do not change it blindly. Laravel writes created_at in app timezone into timestamp-without-tz columns, so changing it mixes old and new rows. (The §9 cron time assumes Asia/Manila.)

`cache_locks` check (Supabase SQL editor, read-only): `select to_regclass('public.cache_locks') is not null as has_table, (select count(*) from public.cache_locks) as rows;` — expect `has_table = true`. A missing table makes every cron run and Run Now fail with 500.

Full list of names read by `config/*.php` (env() calls; none outside config/, CODE-VERIFIED) also includes framework defaults not used in this stack (AWS_*, REDIS_*, MEMCACHED_*, SQS_*, BEANSTALKD_*, DYNAMODB_*, PAPERTRAIL_*, LOG_SLACK_*, SLACK_*).

Frontend (`C:\Users\Dave\Desktop\threejs`, read only, CODE-VERIFIED): VITE_API_URL (`src/App.jsx:14`, `src/utils/fileUrl.js`, `src/pages/auth/Login.jsx:81`) is the host root with no `/api` suffix → `https://api.<domain>`; VITE_MODEL_BASE_URL (`src/lib/modelUrl.js`, `AIMaterials.jsx:318`); VITE_GOOGLE_LOGIN (`Login.jsx:20`, `AuthSkeleton.jsx:24`). Frontend `vercel.json` has only an SPA rewrite and a main-only build gate; no domain values.

## 2. Database and migrations

| Migration | vfrb_demo | Live (Supabase) | Source |
|---|---|---|---|
| 2026_10_29_000001_add_delivery_method_to_orders | UNVERIFIED (not queried) | Ran, batch 50 | SYSTEM_RECAP §4, memory |
| 2026_10_30_000001_add_hot_path_indexes | UNVERIFIED | Ran, batch 51 (2026-10-31) | SYSTEM_RECAP §4; user `migrate:status` 2026-10-31 |
| 2026_10_30_000002_add_tracking_updated_index | UNVERIFIED | Ran, batch 52 (2026-10-31) | SYSTEM_RECAP §4; user `migrate:status` 2026-10-31 |
| 2026_10_12_000001_remove_estimate_columns_and_usage_rates | — | FORBIDDEN to run; already Ran batch 38 | RELEASE_AUDIT §5 |
| 2026_11_02_000001_enable_rls_on_remaining_tables | UNVERIFIED | NOT applied (pending) | SECURITY_AUDIT §6 |

Resolved 2026-11-02: the earlier "not on live" note was wrong. The user applied both 2026_10_30 migrations with `--path` on 2026-10-31 and `migrate:status` showed them Ran (batches 51, 52).

Only pending migration: 2026_11_02_000001. Live already has RLS on all 49 public tables (`pg_class` check 2026-11-02, SECURITY_AUDIT §6), so it changes nothing on live and exists for fresh installs; it has no policies and is a no-op on MySQL. Applying it is optional and the user's call; the deploy does not depend on it.

If it is applied:
1. Supabase backup / PITR snapshot (dashboard, UNVERIFIED, check docs). Record the time.
2. `migrate:status` on live; confirm everything above shows Ran, including 2026_10_12.
3. Run that one file with `--path=database/migrations/2026_11_02_000001_enable_rls_on_remaining_tables.php`. Never plain `migrate`.
4. `migrate:status` again; then deploy code.

Duplicate index on `public.orders` (idx_orders_design_id vs orders_design_id_foreign): no repo migration creates either name; `orders` has no `Schema::create` here (base table predates the repo migrations). Origin UNVERIFIED (likely manual SQL or Supabase advisor). Do not drop orders_design_id_foreign (FK-backed). If anything, review idx_orders_design_id later; no change in this release.

## 3. Postgres risks (MySQL-specific code)

| File:line | Issue | Status |
|---|---|---|
| 2026_04_21_000001, 2026_08_25_000001, 2026_09_07_000001, 2026_10_05_000001 | MySQL-only `MODIFY` | Driver-guarded 2026-11-02: skip on pgsql, MySQL unchanged. CODE-VERIFIED |
| 2026_04_21_000002 `fixForeignKeyIfNeeded()` | MySQL-only `DATABASE()` lookup | Helper skips on pgsql; table create still runs. CODE-VERIFIED |
| app/Http/Controllers/Api/MessageController.php:47,69,151,172,203,234 | `is_read = 0` reads | Live `order_messages.is_read` is smallint (confirmed 2026-11-02), so plain `= 0` is valid on PG. No change. CODE-VERIFIED |
| idx_om_read_order (`is_read`,`order_id`), migration 2026_10_30_000001 | Unread counts filter on plain `is_read = 0`, so PG can use this index | Index use on live UNVERIFIED (check with `EXPLAIN`) |
| `update(['is_read' => 1])` and `'is_read' => 0` inserts app-wide (`order_messages`, `notifications`) | Int values into smallint columns | Live type smallint for both, confirmed 2026-11-02 (information_schema). Type match |
| `purchase_orders.color_mismatch`, `color_confirmed` (reads ProductionStageService.php:639, AdminDashboardController.php:99-100) | Migration 2026_06_08_000001 declares `boolean()` (PG `boolean`), code binds 0/1 | Live type smallint default 0, confirmed 2026-11-02 (information_schema). Type match; do not re-run that migration on a fresh PG without checking |
| 21 other 0/1 flags (qc_checklists.*, orders.qc_required/deadline_negotiated, physical_count_logs.*, materials.ai_eligible, suppliers/designs.is_active, *.is_default, etc.) | Raw `CASE WHEN passed=1` / `stock_adjusted=1` at ActivityLogController.php:76,86; model `boolean` casts | Live type smallint for all 21, confirmed 2026-11-02. Type match |
| `recommendation_guidance.is_active` (Model RecommendationGuidance.php:22, controller :25,34,45) | Only live `boolean` flag (migration 2026_10_13_000001 `boolean()`); code uses `where(..., true)`, model cast, no raw 0/1 | No change. PG UNVERIFIED: smoke test create/retire a guidance rule, then AI recommend |
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
- Model: already env-driven — GEMINI_MODEL (`config/services.php:49`, default `gemini-3.8-flash`) and GEMINI_FALLBACK_MODEL (`:50`). Both gemini-3.8-flash and gemini-3.6-flash are VERIFIED stable on ai.google.dev/gemini-api/docs/models. Note `GeminiClient.php:17` second-argument default is `gemini-3.6-flash`, which differs from the config default (harmless; config wins). No code change needed.
- Guard: prompt forbids quantity/length/yardage/price (`AIController.php:812`, `:551`); customer output is whitelisted to category/unit + catalog facts via `array_intersect_key` (`AIController.php:433-446`); Studio edits are schema-validated enums (`app/Services/DesignEditValidator.php:67-73`). Server-side scan: the strict `MaterialCatalogMatcher::isUnsafeAiText` (`:346`) rejects quantities/prices in per-material notes (`MaterialCatalogMatcher.php:165`, `MaterialRecommender.php:161`), the overall narration (`MaterialCatalogMatcher.php:173`), design-edit summaries (`AIController.php:556`) and stored notes on read (`MaterialRecommendation.php:22`). CODE-VERIFIED, covered by tests.

## 8. Deploy steps, smoke, rollback

1. Backup: Supabase snapshot (UNVERIFIED); note current Vercel production deployment IDs (backend + frontend).
2. Migrations per §2 only if `migrate:status` shows pending.
3. DNS (Cloudflare): `api` CNAME → Vercel backend, `app` CNAME → Vercel frontend; add both domains in Vercel. Proxy mode and SSL "Full (strict)" vs DNS-only: UNVERIFIED, check docs.
4. SSL: wait for Vercel certificates on both hosts (UNVERIFIED).
5. Env: backend APP_URL, FRONTEND_URL, CORS_ALLOWED_ORIGINS (old origin), GOOGLE_REDIRECT_URI, MAIL_FROM_ADDRESS; frontend VITE_API_URL (Vite inlines it at build, so the frontend must be rebuilt).
6. Redeploy backend first, then frontend (frontend needs new endpoints; SYSTEM_RECAP §10).
7. Smoke (tick each; use a test customer and a test order, note IDs here):
   - [ ] `GET /api/version` shows the new APP_RELEASE/APP_DEPLOY_ID.
   - [ ] Login works for customer, manager, staff, each station, owner (and Google login if enabled).
   - [ ] Owner write → 403 (e.g. `POST /api/admin/recommendation-guidance`); station write on another stage → 403.
   - [ ] Customer saves a design in Studio (draft ~200 KB) and reopens it; preview image loads from the new host.
   - [ ] Customer submits an order; manager sees it in `GET /api/admin/orders`.
   - [ ] AI recommend: `POST /api/customer/ai/recommend-materials {order_id}` returns raw-material types only (no quantity/price).
   - [ ] Guidance: manager creates via `POST /api/admin/recommendation-guidance`, retires via `PUT /api/admin/recommendation-guidance/{id}` (inactive); list reflects both.
   - [ ] `GET /api/admin/orders/{id}/ai-context` and `GET /api/admin/ai/recommendation-metrics` return 200 JSON.
   - [ ] `GET /api/admin/activity-log` lists the order submit and guidance changes above.
   - [ ] `GET /api/admin/dashboard` shows the Overdue count (≥ 0, never negative).
   - [ ] Forced error returns clean JSON: `GET /api/does-not-exist` → 404 JSON, an invalid POST body → 422 JSON with `errors`; no HTML, no stack trace. A real 500 has no safe trigger route; confirm in logs that any 500 body is the fixed message (`bootstrap/app.php:120-135`, CODE-VERIFIED).
   - [ ] File upload (≤5 MB png/pdf); password-reset email link uses the new host.
   - [ ] Cron smoke per §9.

Rollback triggers: login fails for any role, CORS errors in browser, 5xx on order create or draft save, previews 404, AI returns quantities/prices, cron 401/500.
Rollback steps:
1. Vercel → Deployments → previous production deployment → Instant Rollback, backend first, then frontend (IDs recorded in step 1). Per Vercel docs, rollback restores that deployment's cron definitions; keep the project's "Disable Cron Jobs" off unless the cron itself is the fault.
2. Restore the old env values (FRONTEND_URL, APP_URL, CORS_ALLOWED_ORIGINS, GOOGLE_REDIRECT_URI, VITE_API_URL); env changes need a redeploy to take effect (UNVERIFIED for Instant Rollback, check docs).
3. DNS: point `api`/`app` back or leave `*.vercel.app` live as the working origin.
4. DB: no restore unless a migration damaged data; all listed migrations are additive, so `migrate:rollback --path=<one file>` (user runs it) beats a full Supabase restore.
5. Record what failed and the time in this file.

## 9. Scheduled automations on Vercel (added 2026-11-02)

Vercel runs no `schedule:run`, so both daily jobs in `app/Support/ScheduledJobs.php` need an HTTP trigger: `vfrb:daily-digest` (07:00: low-stock digest, auto RFQs, deadline reminders, overdue flags, client supply/return, weekly count) and `vfrb:kpi-snapshot` (23:55; Dashboard also captures it lazily). Times are in APP_TIMEZONE.

`GET|POST /api/cron/run` with `Authorization: Bearer <CRON_SECRET>` (constant-time compare, else 401) runs every job whose time today has passed; a cron run already in progress returns 409. Both jobs dedupe, so a repeat call adds nothing. The digest holds a cache lock (`vfrb:daily-digest`, 300 s TTL) shared by Run Now, cron, `schedule:run` and the CLI: a second run is skipped (Run Now: 200 `skipped: true`; cron: listed in `skipped`; CLI: exit 75). Locks use CACHE_STORE (`database` needs the `cache_locks` table on live: UNVERIFIED). Tests: `tests/Feature/AutomationCronTest.php`. CODE-VERIFIED.

Backend `vercel.json` entry (added 2026-11-02, takes effect on the next production deploy): one daily call, `{ "path": "/api/cron/run", "schedule": "0 23 * * *" }` = 07:00 Asia/Manila (UTC+8, no DST).

Vercel facts (docs read 2026-11-02: cron-jobs/usage-and-pricing, updated 2026-07-15; manage-cron-jobs, updated 2026-08-11):
- Hobby: 100 cron jobs per project, at most once per day (more frequent expressions fail the deploy), fired anytime within the hour (±59 min). So the call lands 07:00–07:59 Manila, never before 07:00. VERIFIED (docs).
- CRON_SECRET is sent automatically as `Authorization: Bearer <CRON_SECRET>`, matching `AutomationController::cron` (`bearerToken()` + `hash_equals`). VERIFIED (docs) + CODE-VERIFIED.
- No retries on failure; delivery is best effort and may duplicate. Cron does not follow redirects. Both jobs dedupe and the run lock covers overlap. VERIFIED (docs).
- Schedule is UTC; HTTP method is GET; cron targets the production deployment only: UNVERIFIED (not stated on the pages read). Endpoint accepts GET and POST either way.

Timezone (CODE-VERIFIED): `ScheduledJobs::due()` compares `now()->format('H:i')` with the job times; `now()` uses `app.timezone` (= APP_TIMEZONE, default UTC, `config/app.php:21`). With APP_TIMEZONE=Asia/Manila the 23:00 UTC call is 07:xx local → digest runs, dated the Manila day. If live APP_TIMEZONE is UTC, the call is 23:xx UTC → digest still runs but dated the previous Manila day; live value UNVERIFIED, set it per §1. `vfrb:kpi-snapshot` (23:55) is never due at 07:xx, so on Hobby it relies on the Dashboard's lazy capture; a second daily cron at `55 15 * * *` would fire 23:55–00:54 Manila and could miss the day, so it is not added.

`/api/cron/run` reaches `api/index.php` via the catch-all route (CODE-VERIFIED). Smoke after deploy: `curl -H "Authorization: Bearer $CRON_SECRET" https://api.<domain>/api/cron/run` → 200 with `ran` listing `vfrb:daily-digest` (if after 07:00 local); call again → 200, no new notifications; without the header → 401. Next morning: Vercel → Settings → Cron Jobs → View Logs shows one 200.

Automation decisions (2026-11-02): pending orders past the customer's preferred date flag managers as `order_review_overdue` and get no customer 7-day reminder until an agreed date exists; the dashboard shows a lasting Overdue count (`App\Support\Overdue`); Cutting sends the instant low-stock alert. Customer reminder for an overdue invoice: recommended, pending VFRB confirmation (not built). Late orders: no automatic customer message.

## Open risks

- RLS migration 2026_11_02_000001 not applied on live; optional, user's call (§2).
- Duplicate orders index origin unknown (§2).
- CLOUDINARY_CLOUD_NAME on Vercel (§4). Display_errors and body limits on Vercel (§5, §6).
