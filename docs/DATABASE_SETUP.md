# Database setup

## Empty PostgreSQL (development, CI)

`php artisan migrate` on an empty database loads `database/schema/pgsql-schema.sql` (structure of the 2026-10-13 Supabase dump, 43 tables, no data) and then runs every later migration. Verified on PostgreSQL 16: 57 migrations ran and `orders.status` accepts `for_delivery`.

The migrations alone cannot build a database: the original tables were imported from SQL, not created by migrations. Keep the baseline file; do not delete it.

## Demo data

`DB_DATABASE=vfrb_demo php artisan db:seed --class=DemoDataSeeder` (refuses any other database name). It needs the `manager`, `staff` and `customer` rows in `roles` first; `DatabaseSeeder` does not create them and its `DefenseSeeder` call no longer exists.

## Tests

Feature tests write to the database and refuse to run unless it is named `vfrb_test`. Build it by seeding `vfrb_demo`, cloning it (`CREATE DATABASE vfrb_test TEMPLATE vfrb_demo`) and running `php artisan db:seed --class=CiMaterialsSeeder` on it; `.github/workflows/backend-tests.yml` does exactly this. Six classes still expect a hand-built local database and run as an informational CI step.

## Production (Supabase)

Never run plain `php artisan migrate` or `migrate:fresh` against live. Apply one migration at a time with `--path`, after a backup (see `DEPLOY_RUNBOOK.md` section 2).
