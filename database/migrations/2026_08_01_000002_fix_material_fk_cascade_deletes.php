<?php
// database/migrations/2026_08_01_000002_fix_material_fk_cascade_deletes.php
// (Renamed from 000001 to 000002 — this branch already used 000001 for
// create_material_usage_rates_table.php. Both apply cleanly in sequence.)
//
// AUDIT FINDING (verified against vfrb_db.sql): three foreign keys point at
// materials.material_id with three different ON DELETE behaviors:
//   material_recommendations.material_id -> SET NULL   (correct)
//   inventory_logs.material_id           -> CASCADE     (wrong)
//   rfq_requests.material_id             -> CASCADE     (wrong)
//
// InventoryController::destroy() is a real, wired DELETE endpoint with no
// history check and no soft-delete anywhere in this schema (no deleted_at
// column exists on any table). So deleting one discontinued material would
// silently and permanently wipe every inventory_logs entry (every MIGO
// deduction, every PO goods-receipt) and every RFQ ever requested for it —
// destroying exactly the audit trail this system exists to preserve.
//
// Fix: RESTRICT instead of CASCADE on both. A material with real history
// simply can't be deleted — matches real accounting practice (archive/stop
// using it, don't erase it). InventoryController::destroy() now also checks
// for this up front and returns a clear message instead of a raw SQL error.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_logs', function (Blueprint $table) {
            $table->dropForeign(['material_id']);
        });
        Schema::table('inventory_logs', function (Blueprint $table) {
            $table->foreign('material_id')
                  ->references('material_id')->on('materials')
                  ->onDelete('restrict');
        });

        Schema::table('rfq_requests', function (Blueprint $table) {
            $table->dropForeign(['material_id']);
        });
        Schema::table('rfq_requests', function (Blueprint $table) {
            $table->foreign('material_id')
                  ->references('material_id')->on('materials')
                  ->onDelete('restrict');
        });
    }

    public function down(): void
    {
        // Reverts to the original (flawed) CASCADE behavior — only here for
        // migration reversibility, not something you'd actually want to run.
        Schema::table('inventory_logs', function (Blueprint $table) {
            $table->dropForeign(['material_id']);
        });
        Schema::table('inventory_logs', function (Blueprint $table) {
            $table->foreign('material_id')
                  ->references('material_id')->on('materials')
                  ->onDelete('cascade');
        });

        Schema::table('rfq_requests', function (Blueprint $table) {
            $table->dropForeign(['material_id']);
        });
        Schema::table('rfq_requests', function (Blueprint $table) {
            $table->foreign('material_id')
                  ->references('material_id')->on('materials')
                  ->onDelete('cascade');
        });
    }
};
