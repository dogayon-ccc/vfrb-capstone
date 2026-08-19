<?php
// database/migrations/2026_08_01_000001_create_material_usage_rates_table.php
//
// Deterministic BOM engine — rule-based, NOT the LLM.
//
// Architecture (per user's Aug 1 2026 clarification of AI scope):
//   AI (Gemini) — customer-facing narration ONLY. Given the order's design,
//                 it selects which materials from the real catalog are
//                 relevant and explains why in plain language. It NEVER
//                 outputs a quantity, yardage, or formula.
//   This table  — the deterministic engine that turns "material X is
//                 relevant to this garment_type" into a real number:
//                 needed_qty = qty_per_unit * order.quantity_ordered.
//                 qty_per_unit is set by VFRB staff, not invented by us —
//                 this table ships EMPTY. We do not know VFRB's real
//                 consumption rates (the interview about their proprietary
//                 formula was explicitly excluded from this system's scope),
//                 so we do not guess a number here. Until staff configures
//                 a rate for a given (material_id, garment_type) pair, the
//                 deterministic engine reports the estimate as "not yet
//                 configured" rather than fabricating a figure — see
//                 AIController::computeDeterministicQty().
//
// Columns match MaterialUsageRate model fillable exactly.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('material_usage_rates')) {
            Schema::create('material_usage_rates', function (Blueprint $table) {
                $table->id('rate_id');
                $table->unsignedBigInteger('material_id');
                $table->foreign('material_id')->references('material_id')->on('materials')->cascadeOnDelete();
                $table->string('garment_type', 50);
                // Quantity of this material needed per ONE garment piece —
                // staff-entered, never AI-generated. e.g. 2.5000 (yards).
                $table->decimal('qty_per_unit', 10, 4);
                $table->string('unit', 20);
                $table->unsignedBigInteger('set_by')->nullable();
                $table->foreign('set_by')->references('user_id')->on('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['material_id', 'garment_type']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('material_usage_rates');
    }
};
