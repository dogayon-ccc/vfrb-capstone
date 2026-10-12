<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Removes the leftovers of the old quantity/BOM design. The AI now recommends
// material types only, so these columns and the usage-rate table hold no live data.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('material_recommendations', function (Blueprint $table) {
            foreach (['estimated_range', 'total_estimated_range'] as $col) {
                if (Schema::hasColumn('material_recommendations', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::dropIfExists('material_usage_rates');
    }

    public function down(): void
    {
        Schema::table('material_recommendations', function (Blueprint $table) {
            if (!Schema::hasColumn('material_recommendations', 'estimated_range')) {
                $table->string('estimated_range', 120)->nullable()->after('category');
            }
            if (!Schema::hasColumn('material_recommendations', 'total_estimated_range')) {
                $table->string('total_estimated_range', 120)->nullable()->after('estimated_range');
            }
        });

        if (!Schema::hasTable('material_usage_rates')) {
            Schema::create('material_usage_rates', function (Blueprint $table) {
                $table->id('rate_id');
                $table->unsignedBigInteger('material_id');
                $table->string('garment_type', 50);
                $table->decimal('qty_per_unit', 10, 4);
                $table->string('unit', 20);
                $table->unsignedBigInteger('set_by')->nullable();
                $table->timestamps();
                $table->unique(['material_id', 'garment_type'], 'material_usage_rates_material_id_garment_type_unique');
                $table->foreign('material_id')->references('material_id')->on('materials')->onDelete('cascade');
                $table->foreign('set_by')->references('user_id')->on('users')->onDelete('set null');
            });
        }
    }
};
