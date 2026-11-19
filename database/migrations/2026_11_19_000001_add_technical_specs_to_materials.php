<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Verified technical specs for materials. Every column is nullable: NULL means "not recorded", never a default value,
// so existing materials stay valid and are never given invented properties. Canonical units: cm, gsm, percent.
//   spec_provenance : JSON { attribute: { source, reference, recorded_at } }
//   spec_verified_* : set only when staff confirm the specs against a document or a measurement
return new class extends Migration
{
    private const COLUMNS = [
        'fiber_composition', 'gsm', 'usable_width_cm', 'stretch_pct', 'stretch_direction', 'construction',
        'finish', 'shrinkage_pct', 'colourfastness_grade', 'spec_provenance', 'spec_verified_at', 'spec_verified_by',
    ];

    public function up(): void
    {
        Schema::table('materials', function (Blueprint $t) {
            if (!Schema::hasColumn('materials', 'gsm')) {
                $t->json('fiber_composition')->nullable();
                $t->decimal('gsm', 6, 1)->nullable();
                $t->decimal('usable_width_cm', 6, 1)->nullable();
                $t->decimal('stretch_pct', 5, 1)->nullable();
                $t->string('stretch_direction', 12)->nullable();
                $t->string('construction', 12)->nullable();
                $t->string('finish', 60)->nullable();
                $t->decimal('shrinkage_pct', 4, 1)->nullable();
                $t->decimal('colourfastness_grade', 2, 1)->nullable();
                $t->json('spec_provenance')->nullable();
                $t->timestamp('spec_verified_at')->nullable();
                $t->unsignedBigInteger('spec_verified_by')->nullable();
            }
        });

        // What VFRB managers require of a kind of garment's fabric. Empty until staff define it: nothing is assumed.
        if (!Schema::hasTable('material_spec_requirements')) {
            Schema::create('material_spec_requirements', function (Blueprint $t) {
                $t->id();
                $t->string('garment_kind', 20);        // top | bottom | skirt | dress (MaterialCatalogMatcher::garmentProfile kind)
                $t->string('attribute', 30);           // gsm | usable_width_cm | stretch_pct | construction | fiber:<name> | ...
                $t->decimal('min_value', 8, 2)->nullable();
                $t->decimal('max_value', 8, 2)->nullable();
                $t->string('text_value', 60)->nullable();
                $t->boolean('is_required')->default(false); // true: a verified mismatch removes the material before ranking
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
                $t->unique(['garment_kind', 'attribute']);
            });
        }

        // Frozen at recommendation time so staff can audit what the engine knew then. Not part of CUSTOMER_COLUMNS.
        if (Schema::hasTable('material_recommendations') && !Schema::hasColumn('material_recommendations', 'spec_snapshot')) {
            Schema::table('material_recommendations', fn (Blueprint $t) => $t->json('spec_snapshot')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('material_recommendations', 'spec_snapshot')) {
            Schema::table('material_recommendations', fn (Blueprint $t) => $t->dropColumn('spec_snapshot'));
        }
        Schema::dropIfExists('material_spec_requirements');
        $present = array_values(array_filter(self::COLUMNS, fn ($c) => Schema::hasColumn('materials', $c)));
        if ($present) {
            Schema::table('materials', fn (Blueprint $t) => $t->dropColumn($present));
        }
    }
};
