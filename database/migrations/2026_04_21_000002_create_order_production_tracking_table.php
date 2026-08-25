<?php
// database/migrations/2026_04_21_000002_create_order_production_tracking_table.php
//
// ═══════════════════════════════════════════════════════════════════════════
// FIX SUMMARY (4 changes from original):
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
//
// FIX 4 (Aug 25 2026) — stage enum was missing 2 of the 7 real pipeline
//   stages ('segregation' and 'pressing'). Confirmed by reading
//   ProductionController.php directly: its own header comment says
//   "stage (enum 7)", its STAGE_MAP and logProgress() validation both
//   require all 7 values (pattern → segregation → cutting → sewing → qc
//   → pressing → packing), but this file only ever created 5. Any
//   already-migrated database needs the separate repair migration
//   (2026_08_25_000001_fix_order_production_tracking_stage_enum.php) —
//   ALTERing an ENUM on a live table with an idempotent-guarded
//   Schema::create() migration like this one won't retroactively widen
//   it. This file is fixed so a FRESH environment (new clone, new
//   database) gets the correct enum on the very first migrate, without
//   needing the repair migration to run afterward.
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

            // ── FIX 4: All 7 real pipeline stages, not 5 ───────────────────
            // Matches ProductionController::STAGE_MAP and its
            // logProgress() validation rule exactly — those two must never
            // drift apart again.
            $table->enum('stage', ['pattern', 'segregation', 'cutting', 'sewing', 'qc', 'pressing', 'packing']);

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
