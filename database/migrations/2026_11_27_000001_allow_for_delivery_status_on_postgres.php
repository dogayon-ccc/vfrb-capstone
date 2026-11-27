<?php
// 2026_10_17_000003 widened orders.status to include 'for_delivery' on MySQL only; PostgreSQL kept the old CHECK, so the
// automatic packing -> for_delivery step fails there. Additive: re-creates orders_status_check with the full value list.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const STATUSES = ['pending', 'confirmed', 'pattern', 'segregation', 'cutting', 'sewing', 'qc', 'pressing', 'packing', 'for_delivery', 'completed', 'cancelled'];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $def = DB::selectOne("SELECT pg_get_constraintdef(oid) AS d FROM pg_constraint WHERE conrelid = 'orders'::regclass AND conname = 'orders_status_check'")->d ?? null;
        if ($def !== null && str_contains($def, "'for_delivery'")) {
            return;
        }

        $list = implode(', ', array_map(fn ($s) => "'{$s}'", self::STATUSES));
        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_status_check');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status::text IN ({$list}))");
    }

    public function down(): void
    {
        // Intentionally empty: narrowing the CHECK could reject rows already holding 'for_delivery'.
    }
};
