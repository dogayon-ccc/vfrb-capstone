# VFRB Enterprise API

Laravel 12 backend (PHP ^8.4) for the VFRB sales, job-order and inventory system: Sanctum auth, Spatie roles (manager, staff, customer, owner read-only), Gemini-based raw-material recommendation. The React frontend lives in a separate repository. Production runs on Vercel (`api/index.php`) with Supabase PostgreSQL.

## Run locally

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate        # empty PostgreSQL: loads database/schema/pgsql-schema.sql, then the remaining migrations
php artisan serve
```

Set `GEMINI_KEY_1` (and optionally `_2`, `_3`) for the recommender. See `docs/DATABASE_SETUP.md` for databases and tests.

## Where things are

| Path | Contents |
|---|---|
| `routes/api.php` | All API routes, grouped by customer, staff and admin |
| `app/Http/Controllers/Api` | One controller per area (orders, inventory, production, purchasing, AI) |
| `app/Services` | Recommender, catalogue and spec matching, production stages, billing, validators |
| `app/Support` | Small pure helpers (labels, overdue rules, review statistics) |
| `app/Console/Commands` | `php artisan vfrb:*` tasks, auto-discovered (`php artisan list vfrb`) |
| `database/schema` | PostgreSQL baseline the migrations build on |
| `database/seeders` | Demo data and the CI catalogue fixture |
| `tests` | PHPUnit; Feature tests run only on a database named `vfrb_test` |

## Docs

| File | Use it for |
|---|---|
| `docs/DATABASE_SETUP.md` | Building a database, test database rules, what never to run on production |
| `docs/DEPLOY_RUNBOOK.md` | Environment variables, migrations on live, PostgreSQL risks |
| `docs/SECURITY_AUDIT.md` | Findings and re-verification status |
| `docs/AI_REVIEW.md` | Running and reporting the AI recommendation review |
| `docs/API.md` | Generated endpoint list (`php scripts/api-docs.php`); do not edit by hand |
| `docs/SYSTEM_RECAP.md` | System overview as of 2026-10-31 |
| `docs/RELEASE_AUDIT_BACKEND.md`, `docs/CHANGE_REPORT_OCT20_TO_OCT31.md` | Historical: the Oct 21 overhaul |
