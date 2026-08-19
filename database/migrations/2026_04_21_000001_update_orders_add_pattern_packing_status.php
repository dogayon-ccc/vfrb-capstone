<?php
// database/migrations/2026_04_21_000001_update_orders_add_pattern_packing_status.php
// ALREADY PROVIDED BY YOU — INCLUDED AS-IS
// This migration adds 'pattern' and 'packing' to orders.status ENUM

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE orders
            MODIFY COLUMN status
            ENUM(
                'pending','confirmed','pattern','cutting',
                'sewing','qc','packing','completed','cancelled'
            ) NOT NULL DEFAULT 'pending'
        ");

        // Check if production_data column exists, add if not
        $cols = DB::select("SHOW COLUMNS FROM orders LIKE 'production_data'");
        if (empty($cols)) {
            // Note: production_progress is stored in notes JSON for simplicity
            // No migration needed — AdminOrderController uses notes JSON field
            // If you want a dedicated column, uncomment:
            // DB::statement("ALTER TABLE orders ADD COLUMN production_data JSON NULL AFTER notes");
        }
    }

    public function down(): void
    {
        DB::statement("UPDATE orders SET status = 'confirmed' WHERE status IN ('pattern','packing')");
        DB::statement("
            ALTER TABLE orders
            MODIFY COLUMN status
            ENUM('pending','confirmed','cutting','sewing','qc','completed','cancelled')
            NOT NULL DEFAULT 'pending'
        ");
    }
};