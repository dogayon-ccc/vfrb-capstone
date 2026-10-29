<?php

// Enables RLS on tables Supabase flagged; the app connects as owner and bypasses RLS. No-op on MySQL.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TABLES = [
        'invoices',
        'payment_receipts',
        'audit_logs',
        'customer_contacts',
        'client_material_ledger',
        'recommendation_guidance',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::TABLES as $table) {
            $exists = DB::selectOne('SELECT to_regclass(?) AS oid', ["public.{$table}"])->oid !== null;

            if ($exists) {
                DB::statement("ALTER TABLE public.{$table} ENABLE ROW LEVEL SECURITY");
            }
        }

        $functionExists = DB::selectOne("SELECT to_regprocedure('public.audit_logs_block_mutation()') AS oid")->oid !== null;

        if ($functionExists) {
            DB::statement('ALTER FUNCTION public.audit_logs_block_mutation() SET search_path = public, pg_temp');
        }
    }

    public function down(): void
    {
        //
    }
};
