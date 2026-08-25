<?php
// database/migrations/2026_08_25_000001_fix_order_production_tracking_stage_enum.php
//
// ═══════════════════════════════════════════════════════════════════════════
// WHY THIS MIGRATION EXISTS — read this before touching the enum again
//
// order_production_tracking.stage was created with only 5 values:
//   ['pattern', 'cutting', 'sewing', 'qc', 'packing']
//
// But the real, locked production pipeline (used everywhere else in this
// system — orders.status, ProductionController::STAGE_MAP, the frontend
// nav, this very controller's own logProgress() validation rule) has 7:
//   ['pattern', 'segregation', 'cutting', 'sewing', 'qc', 'pressing', 'packing']
//
// CONCRETE BUG THIS CAUSED (confirmed by reading ProductionController.php
// directly, not assumed): logProgress() does a raw DB::table(...)->insert()
// with the stage value straight from the validated request (line ~224).
// Staff logging progress while an order sits in Segregation or Pressing
// would either hard-fail (MySQL strict mode) or silently truncate the
// stage to an empty string (relaxed mode) — the second case is the
// dangerous one: no error, just a corrupted row that then breaks the
// pipeline display, the delayed-orders report, and ActivityLogController's
// stage_progress feed for that order, with nothing in the logs pointing
// at why.
//
// HOW TO VERIFY THIS WAS A REAL BUG (do this before/after applying, so
// it's demonstrated, not just asserted):
//   1. Before this migration: try inserting a row with stage='segregation'
//      directly in phpMyAdmin/Tinker — watch it fail or truncate.
//   2. After this migration: same insert succeeds cleanly.
//
// THIS MIGRATION DOES NOT TOUCH ANY EXISTING ROWS. It only widens what
// values the column is *allowed* to hold — MODIFY COLUMN on an ENUM is a
// metadata change, not a data rewrite. Any row already using one of the
// original 5 values is completely unaffected.
// ═══════════════════════════════════════════════════════════════════════════

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Guard: if the table doesn't exist yet on some environment (e.g.
        // a fresh database that hasn't run the original migration), skip
        // silently — the ORIGINAL migration file should be fixed
        // separately for any brand-new setup. This migration's job is
        // specifically to repair the table where it ALREADY exists.
        if (!Schema::hasTable('order_production_tracking')) {
            return;
        }

        // Raw SQL is necessary here — Laravel's schema builder has no
        // "modify enum values" helper; ENUM alteration is MySQL-specific
        // and must be done via a raw ALTER TABLE ... MODIFY COLUMN.
        DB::statement("
            ALTER TABLE order_production_tracking
            MODIFY COLUMN stage
            ENUM('pattern','segregation','cutting','sewing','qc','pressing','packing')
            NOT NULL
        ");
    }

    public function down(): void
    {
        // Reverting to the original 5-value enum would be actively unsafe
        // if any 'segregation' or 'pressing' rows exist by the time someone
        // rolls back — MySQL would truncate them. Refuse to roll back
        // rather than silently reintroduce the bug this migration fixes.
        if (!Schema::hasTable('order_production_tracking')) {
            return;
        }

        $hasNewStageRows = DB::table('order_production_tracking')
            ->whereIn('stage', ['segregation', 'pressing'])
            ->exists();

        if ($hasNewStageRows) {
            throw new \RuntimeException(
                'Refusing to roll back: order_production_tracking has rows ' .
                'with stage=segregation or stage=pressing. Reverting the ' .
                'enum would corrupt those rows. Resolve manually if you ' .
                'genuinely need to roll back.'
            );
        }

        DB::statement("
            ALTER TABLE order_production_tracking
            MODIFY COLUMN stage
            ENUM('pattern','cutting','sewing','qc','packing')
            NOT NULL
        ");
    }
};
