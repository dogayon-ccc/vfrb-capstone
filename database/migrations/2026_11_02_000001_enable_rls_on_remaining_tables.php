<?php

// Enables RLS on every public table not in 2026_10_22_000002. Live already had RLS on all 49 tables (checked 2026-11-02), so this records it for fresh installs.
// No policies: Laravel connects as the table owner, which bypasses RLS; anon/authenticated PostgREST roles get no rows. No-op on MySQL.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TABLES = [
        'billing_profiles', 'cache', 'cache_locks', 'company_settings', 'customer_fabric_preferences',
        'daily_output_logs', 'delivery_tracking', 'design_showcase_audit', 'designs', 'failed_jobs',
        'feedback', 'inventory_logs', 'job_batches', 'jobs', 'kpi_daily_snapshots',
        'material_recommendations', 'material_usage_rates', 'materials', 'measurements', 'migrations',
        'model_has_permissions', 'model_has_roles', 'notification_preferences', 'notifications', 'order_drafts',
        'order_messages', 'order_production_tracking', 'orders', 'password_reset_tokens', 'permissions',
        'personal_access_tokens', 'physical_count_logs', 'production_incidents', 'purchase_order_items', 'purchase_orders',
        'qc_checklists', 'rfq_requests', 'rfq_responses', 'role_has_permissions', 'roles',
        'sales_transactions', 'sessions', 'shipping_addresses', 'suppliers', 'users',
    ];

    // No-op down: RLS on live predates this migration, so a rollback must not turn it off.
    public function down(): void
    {
        //
    }

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::TABLES as $table) {
            if (DB::selectOne('SELECT to_regclass(?) AS oid', ["public.{$table}"])->oid !== null) {
                DB::statement("ALTER TABLE public.{$table} ENABLE ROW LEVEL SECURITY");
            }
        }
    }
};
