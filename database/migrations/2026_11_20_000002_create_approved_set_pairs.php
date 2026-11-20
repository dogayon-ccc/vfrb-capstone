<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Additive. The list starts empty on purpose: no top+bottom combination is assumed to be a VFRB set until a manager adds it.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('approved_set_pairs')) {
            Schema::create('approved_set_pairs', function (Blueprint $t) {
                $t->id();
                $t->string('top_garment', 40);
                $t->string('bottom_garment', 40);
                $t->unsignedBigInteger('approved_by')->nullable();
                $t->timestamps();
                $t->unique(['top_garment', 'bottom_garment']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('approved_set_pairs');
    }
};
