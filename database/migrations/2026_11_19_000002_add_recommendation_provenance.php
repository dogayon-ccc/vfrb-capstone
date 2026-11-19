<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Where each recommended line came from, so staff verdicts can be told apart by source and a result can be traced to its inputs.
//   rec_source: 'ai' (Gemini, live or cached) | 'catalog_default' (deterministic fallback); NULL on lines made before this migration
//   rec_meta:   context_hash (designHash of everything the AI saw), model that answered, served_from, engine version, swapped count, time
// Additive and nullable: no existing row or query changes. Never shown to customers.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('material_recommendations', function (Blueprint $t) {
            if (!Schema::hasColumn('material_recommendations', 'rec_source')) { $t->string('rec_source', 20)->nullable(); }
            if (!Schema::hasColumn('material_recommendations', 'rec_meta'))   { $t->json('rec_meta')->nullable(); }
        });
    }

    public function down(): void
    {
        Schema::table('material_recommendations', function (Blueprint $t) {
            foreach (['rec_source', 'rec_meta'] as $c) { if (Schema::hasColumn('material_recommendations', $c)) { $t->dropColumn($c); } }
        });
    }
};
