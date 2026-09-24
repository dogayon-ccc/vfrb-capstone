<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * FIX (Sony Mark, Sept 7 2026) — resolves V-001 in VFRB_CURRENT_TRUTH.md.
 *
 * `material_recommendations.estimated_range` has been `NOT NULL` with no
 * default since the table's original creation, from when Gemini produced
 * a literal quantity range per material (e.g. "34-40 yards per piece").
 * The Aug 28 2026 no-formula / SCOPE-001 redesign removed that
 * calculation entirely — AIController.php's create() call has not set
 * this field since (AIController.php, recommendMaterials(), ~line 117),
 * and no frontend page reads it: grepped OrderDetail.jsx, AIMaterials.jsx,
 * MaterialsReveal.jsx, and admin/OrderDetail.jsx — all four carry code
 * comments explicitly confirming estimated_range is intentionally never
 * read, consistent with the type-only material recommendation scope.
 *
 * Left NOT NULL, every MaterialRecommendation::create() call throws
 * SQLSTATE[HY000]: 1364 under strict mode — reproduced directly against
 * a clean import of the real schema (see VFRB_CURRENT_TRUTH.md V-001).
 * AI Layer 1, the customer-facing material recommendation flow, cannot
 * currently write a single row.
 *
 * RESOLUTION CHOSEN: make the column nullable, not drop it.
 * Dropping is the more aggressive move and would need to take
 * `total_estimated_range` with it, since both serve the same obsolete
 * quantity-range purpose — that's a real data-model call (permanently
 * remove the pair, or leave the schema ready in case a staff/admin-only
 * range feature comes back later?) that belongs to Dave, not something
 * to decide unilaterally in a stabilization-week migration. Nullable is
 * the smallest change that unblocks the insert without foreclosing that
 * decision either way.
 *
 * doctrine/dbal is not installed in this project (checked vendor/doctrine/
 * and composer.json — only doctrine/inflector and doctrine/lexer are
 * present as transitive deps of something else). Laravel's Schema
 * Blueprint ->change() requires doctrine/dbal, so this uses a raw
 * ALTER TABLE instead rather than adding a new dependency mid-week.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE `material_recommendations` " .
            "MODIFY `estimated_range` VARCHAR(120) NULL " .
            "COMMENT 'Range Gemini produces per piece — unused since the Aug 28 2026 no-formula redesign; kept nullable, not dropped, pending a data-model decision on this pair with total_estimated_range'"
        );
    }

    public function down(): void
    {
        // Reversible only if every existing row already has a non-null
        // value — true immediately after this migration runs, but not
        // guaranteed later if new NULL rows have since been inserted.
        DB::statement(
            "ALTER TABLE `material_recommendations` " .
            "MODIFY `estimated_range` VARCHAR(120) NOT NULL " .
            "COMMENT 'Range Gemini produces per piece, e.g. \"34 yards per piece\"'"
        );
    }
};
