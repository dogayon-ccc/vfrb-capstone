<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->string('material_code', 20)->nullable()->unique()->after('material_id');
        });

        $seq = [];
        foreach (DB::table('materials')->orderBy('material_id')->get(['material_id', 'category']) as $m) {
            $cat = $m->category;
            $key = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string) $cat) ?: 'GEN', 0, 3));
            $key = str_pad($key, 3, 'X');
            $seq[$key] = ($seq[$key] ?? 0) + 1;
            DB::table('materials')->where('material_id', $m->material_id)
                ->update(['material_code' => sprintf('MAT-%s-%03d', $key, $seq[$key])]);
        }
    }

    public function down(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->dropUnique(['material_code']);
            $table->dropColumn('material_code');
        });
    }
};
