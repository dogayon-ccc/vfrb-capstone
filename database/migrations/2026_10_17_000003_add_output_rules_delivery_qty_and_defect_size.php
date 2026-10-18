<?php
// Production output rules (Oct 17 2026):
//   orders.status            + 'for_delivery' (set automatically when packed_total == ordered)
//   delivery_tracking        + delivered_qty (partial delivery, capped at packed_total)
//   daily_output_logs        + defect_size; alteration_count gets DEFAULT 0 because the app no longer writes it
// Additive only. down() is a no-op on purpose: no column is ever dropped.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            $type = DB::selectOne(
                "SELECT COLUMN_TYPE AS t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'status'"
            )->t ?? '';
            if (str_starts_with($type, 'enum(') && !str_contains($type, "'for_delivery'")) {
                $values = substr($type, 0, -1) . ",'for_delivery')";
                DB::statement("ALTER TABLE orders MODIFY COLUMN status {$values} NOT NULL DEFAULT 'pending'");
            }
            if (Schema::hasColumn('daily_output_logs', 'alteration_count')) {
                DB::statement('ALTER TABLE daily_output_logs ALTER COLUMN alteration_count SET DEFAULT 0');
            }
        }

        if (!Schema::hasColumn('delivery_tracking', 'delivered_qty')) {
            Schema::table('delivery_tracking', function (Blueprint $table) {
                $table->unsignedInteger('delivered_qty')->default(0)->after('delivery_status');
            });
        }

        if (!Schema::hasColumn('daily_output_logs', 'defect_size')) {
            Schema::table('daily_output_logs', function (Blueprint $table) {
                $table->string('defect_size', 10)->nullable()->after('defect_count');
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty: never drop columns or enum values that may already hold data.
    }
};
