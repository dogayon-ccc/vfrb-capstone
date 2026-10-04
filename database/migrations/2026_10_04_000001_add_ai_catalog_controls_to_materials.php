<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Lets VFRB staff control what the AI may recommend. Both columns are additive and default to
// today's behavior: every material eligible, for every garment.
//   ai_eligible : false hides the material from the AI (it stays in stock and in manual selection)
//   applies_to  : JSON list of garment names (e.g. ["Polo Shirt","Pants"]); NULL/empty = all garments
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            if (!Schema::hasColumn('materials', 'ai_eligible')) {
                $table->boolean('ai_eligible')->default(true)->after('unit_cost');
            }
            if (!Schema::hasColumn('materials', 'applies_to')) {
                $table->json('applies_to')->nullable()->after('ai_eligible');
            }
        });
    }

    public function down(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            foreach (['applies_to', 'ai_eligible'] as $col) {
                if (Schema::hasColumn('materials', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
