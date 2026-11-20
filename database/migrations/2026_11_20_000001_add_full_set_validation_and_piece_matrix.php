<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Additive: a stored, server-owned verdict per uploaded reference, and a top/bottom tag on size rows.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('reference_validations')) {
            Schema::create('reference_validations', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id')->index();
                $t->string('path', 255);
                $t->char('sha256', 64);
                $t->string('status', 20); // accepted | needs_review | rejected | approved (staff-confirmed)
                $t->string('reason', 40);
                $t->string('top_garment', 40)->nullable();
                $t->string('bottom_garment', 40)->nullable();
                $t->json('colors')->nullable();
                $t->string('model', 60)->nullable();
                $t->string('prompt_version', 20);
                $t->unsignedBigInteger('reviewed_by')->nullable();
                $t->timestamp('reviewed_at')->nullable();
                $t->unsignedBigInteger('order_id')->nullable()->unique();
                $t->timestamps();
                $t->index(['user_id', 'sha256']);
            });
        }
        if (! Schema::hasColumn('orders', 'reference_validation_id')) {
            Schema::table('orders', fn (Blueprint $t) => $t->unsignedBigInteger('reference_validation_id')->nullable()->after('client_design_ref_file'));
        }
        if (! Schema::hasColumn('measurements', 'piece')) {
            Schema::table('measurements', fn (Blueprint $t) => $t->string('piece', 10)->nullable()->after('size_label')); // top | bottom; null on legacy rows
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('measurements', 'piece')) {
            Schema::table('measurements', fn (Blueprint $t) => $t->dropColumn('piece'));
        }
        if (Schema::hasColumn('orders', 'reference_validation_id')) {
            Schema::table('orders', fn (Blueprint $t) => $t->dropColumn('reference_validation_id'));
        }
        Schema::dropIfExists('reference_validations');
    }
};
