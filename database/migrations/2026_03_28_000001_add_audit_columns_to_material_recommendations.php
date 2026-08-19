<?php
// database/migrations/2026_03_28_000001_add_audit_columns_to_material_recommendations.php
// Run: php artisan migrate
// Safe — does NOT drop or modify existing rows. Only ADDs columns.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('material_recommendations', function (Blueprint $table) {
            // Only add columns that don't already exist
            if (!Schema::hasColumn('material_recommendations', 'formula_id')) {
                $table->unsignedBigInteger('formula_id')->nullable()->after('material_id')
                    ->comment('FK to material_formulas — enables full BOM traceability for defense');
            }
            if (!Schema::hasColumn('material_recommendations', 'computation_method')) {
                $table->enum('computation_method', ['standard', 'custom'])
                    ->default('standard')->after('formula_id')
                    ->comment('standard = rule-based CS12, custom = TESDA NC II parametric CS01');
            }
            if (!Schema::hasColumn('material_recommendations', 'scale_ratio')) {
                $table->decimal('scale_ratio', 8, 4)->nullable()->after('computation_method')
                    ->comment('chest_cm / 96cm — TESDA NC II M-size baseline. NULL for standard track.');
            }
            if (!Schema::hasColumn('material_recommendations', 'base_qty_per_piece')) {
                $table->decimal('base_qty_per_piece', 10, 4)->nullable()->after('scale_ratio')
                    ->comment('qty_per_piece from material_formulas before scaling');
            }
            if (!Schema::hasColumn('material_recommendations', 'size_label')) {
                $table->string('size_label', 10)->nullable()->after('base_qty_per_piece')
                    ->comment('standard: XS/S/M/L/XL/XXL/XXXL. custom: custom');
            }
        });
    }

    public function down(): void
    {
        Schema::table('material_recommendations', function (Blueprint $table) {
            $table->dropColumnIfExists('formula_id');
            $table->dropColumnIfExists('computation_method');
            $table->dropColumnIfExists('scale_ratio');
            $table->dropColumnIfExists('base_qty_per_piece');
            $table->dropColumnIfExists('size_label');
        });
    }
};