#!/usr/bin/env bash
# scripts/fresh-install.sh — VFRB Enterprise
#
# WHY THIS EXISTS (Aug 27 2026):
# This project's database was never buildable from `php artisan migrate:fresh`
# alone — roughly 22 of 34 tables (including the entire Spatie permission
# package tables: roles, permissions, model_has_roles, model_has_permissions,
# role_has_permissions) have no Schema::create() migration. It was always
# provisioned by importing vfrb_db.sql directly, with every migration since
# then written as an incremental ALTER on top of that import.
#
# Hand-writing ~22 accurate migrations (correct FKs, indexes, enums) 4 days
# before an Aug 31 defense, with no way to test them in this environment,
# is real risk for a payoff (git-clone-and-migrate-fresh parity) that isn't
# actually needed. This script formalizes the process that has already been
# manually proven to work — a reliable "start from a real, clean state"
# path for a new dev machine, CI, or a fresh deploy — without that risk.
#
# WHAT IT DOES:
#   1. Imports the full, current, authoritative schema from vfrb_db.sql
#      (this file IS the source of truth for the schema — ahead of any
#      migration if they ever disagree).
#   2. Truncates every business-data table, leaving the schema and
#      Laravel's/Spatie's framework tables (migrations, roles, permissions,
#      role_has_permissions, model_has_permissions) fully intact.
#   3. Truncates users/model_has_roles/personal_access_tokens TOGETHER,
#      as one batch — truncating auth data separately risks orphaned role
#      assignments; doing both at once means there's never a moment where
#      one is empty and the other isn't.
#   4. Re-creates exactly one manager account so there's always a way to
#      log in immediately after a reset — password is prompted, not
#      hardcoded, so it never ends up committed anywhere.
#
# USAGE:
#   ./scripts/fresh-install.sh
#   (requires: mysql client, DB credentials in backend/.env, php artisan)
#
# This does NOT run migrate:fresh, and should be the standard way this
# project resets to a clean state going forward — not migrate:fresh.

set -euo pipefail
cd "$(dirname "$0")/.."   # repo root (backend/)

if [ ! -f .env ]; then
  echo "ERROR: .env not found. Run this from a configured Laravel install." >&2
  exit 1
fi

DB_NAME=$(grep -E '^DB_DATABASE=' .env | cut -d= -f2)
DB_USER=$(grep -E '^DB_USERNAME=' .env | cut -d= -f2)
DB_HOST=$(grep -E '^DB_HOST=' .env | cut -d= -f2)
DB_HOST=${DB_HOST:-127.0.0.1}

if [ -z "$DB_NAME" ] || [ -z "$DB_USER" ]; then
  echo "ERROR: Could not read DB_DATABASE/DB_USERNAME from .env" >&2
  exit 1
fi

SCHEMA_FILE="../vfrb_db.sql"   # keep the authoritative dump one level up, or adjust this path
if [ ! -f "$SCHEMA_FILE" ]; then
  echo "ERROR: $SCHEMA_FILE not found. This script needs the current, real schema dump." >&2
  exit 1
fi

echo "This will DROP and rebuild '$DB_NAME' from $SCHEMA_FILE, then wipe all business data."
read -r -p "Type the database name to confirm ($DB_NAME): " CONFIRM
if [ "$CONFIRM" != "$DB_NAME" ]; then
  echo "Confirmation did not match. Aborting — nothing was changed." >&2
  exit 1
fi

echo "→ Step 1/4: Importing schema from $SCHEMA_FILE ..."
mysql -h "$DB_HOST" -u "$DB_USER" -p "$DB_NAME" < "$SCHEMA_FILE"

echo "→ Step 2/4: Truncating business-data tables ..."
# Framework/package tables deliberately NOT in this list — they must survive
# a reset intact: cache, cache_locks, jobs, job_batches, failed_jobs,
# migrations, password_reset_tokens, permissions, roles, role_has_permissions,
# model_has_permissions, company_settings (site-wide config, not a business record).
BUSINESS_TABLES=(
  daily_output_logs delivery_tracking designs feedback inventory_logs
  materials material_recommendations material_usage_rates measurements
  notifications notification_preferences orders order_drafts order_messages
  order_production_tracking physical_count_logs production_incidents
  purchase_orders qc_checklists rfq_requests rfq_responses
  sales_transactions suppliers personal_access_tokens
)
{
  echo "SET FOREIGN_KEY_CHECKS=0;"
  for t in "${BUSINESS_TABLES[@]}"; do echo "TRUNCATE TABLE \`$t\`;"; done
  # users + model_has_roles truncated in the SAME batch as everything above —
  # never truncate one without the other, or you risk orphaned role rows.
  echo "TRUNCATE TABLE \`users\`;"
  echo "TRUNCATE TABLE \`model_has_roles\`;"
  echo "SET FOREIGN_KEY_CHECKS=1;"
} | mysql -h "$DB_HOST" -u "$DB_USER" -p "$DB_NAME"

echo "→ Step 3/4: Creating one manager account so you can log in ..."
read -r -p "Manager name [VFRB Manager]: " MGR_NAME
MGR_NAME=${MGR_NAME:-"VFRB Manager"}
read -r -p "Manager email: " MGR_EMAIL
read -r -s -p "Manager password: " MGR_PASS
echo ""

php artisan tinker --execute="
\$id = \DB::table('users')->insertGetId([
    'name' => '$MGR_NAME',
    'email' => '$MGR_EMAIL',
    'password' => \Illuminate\Support\Facades\Hash::make('$MGR_PASS'),
    'job_function' => 'general',
    'email_verified_at' => now(),
    'created_at' => now(),
    'updated_at' => now(),
]);
\$roleId = \DB::table('roles')->where('name', 'manager')->value('id');
\DB::table('model_has_roles')->insert([
    'role_id' => \$roleId,
    'model_type' => 'App\\\\Models\\\\User',
    'model_id' => \$id,
]);
echo \"Created manager user_id=\$id\n\";
"

echo "→ Step 4/4: Done. Database is genuinely clean: real schema, zero orders/materials/test data, one working manager login."
