<?php
// database/migrations/2026_04_21_000002_create_order_production_tracking_table.php
//
// ═══════════════════════════════════════════════════════════════════════════
// FIX SUMMARY (3 changes from original):
//
// FIX 1 — Table already exists error
//   BEFORE: Schema::create() runs unconditionally → crashes on 2nd migrate
//   AFTER:  Wrapped in if (!Schema::hasTable(...)) → safe to run multiple times
//
// FIX 2 — Foreign key references wrong column
//   BEFORE: ->references('id')->on('users')
//   AFTER:  ->references('user_id')->on('users')
//   REASON: vfrb_db.users PRIMARY KEY = user_id (not id)
//           Confirmed in SQL dump line 1064: ADD PRIMARY KEY (`user_id`)
//
// FIX 3 — down() also made safe
//   BEFORE: Schema::dropIfExists() — fine as-is
//   AFTER:  unchanged (dropIfExists is already idempotent)
// ═══════════════════════════════════════════════════════════════════════════

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── FIX 1: Guard against "table already exists" on re-run ─────────
        if (Schema::hasTable('order_production_tracking')) {
            // Table was partially created on a failed previous run.
            // Just ensure the foreign key exists correctly and return.
            $this->fixForeignKeyIfNeeded();
            return;
        }

        Schema::create('order_production_tracking', function (Blueprint $table) {
            $table->id('tracking_id');

            $table->unsignedBigInteger('order_id');
            $table->foreign('order_id')
                  ->references('order_id')
                  ->on('orders')
                  ->onDelete('cascade');

            $table->enum('stage', ['pattern', 'cutting', 'sewing', 'qc', 'packing']);

            $table->unsignedInteger('qty_target')->default(0);
            $table->unsignedInteger('qty_completed')->default(0);

            // ── FIX 2: Reference user_id (not id) ─────────────────────────
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->foreign('updated_by')
                  ->references('user_id')   // ← FIXED: was 'id'
                  ->on('users')
                  ->onDelete('set null');

            $table->string('notes', 500)->nullable();

            $table->unique(['order_id', 'stage'], 'uq_order_stage');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_production_tracking');
    }

    // ── Helper: fix the FK on a table that already exists without the FK ──
    private function fixForeignKeyIfNeeded(): void
    {
        // Check if updated_by FK already exists
        $hasFK = \Illuminate\Support\Facades\DB::select("
            SELECT CONSTRAINT_NAME
            FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'order_production_tracking'
              AND CONSTRAINT_TYPE = 'FOREIGN KEY'
              AND CONSTRAINT_NAME = 'order_production_tracking_updated_by_foreign'
        ");

        if (empty($hasFK)) {
            // The table exists but the FK doesn't — add it now
            Schema::table('order_production_tracking', function (Blueprint $table) {
                $table->foreign('updated_by')
                      ->references('user_id')   // ← correct column
                      ->on('users')
                      ->onDelete('set null');
            });
        }
    }
};
