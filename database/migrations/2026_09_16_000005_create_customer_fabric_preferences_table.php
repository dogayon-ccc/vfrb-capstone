<?php
// database/migrations/2026_09_16_000003_create_customer_fabric_preferences_table.php
//
// Customer saves real materials (category='Fabric') they reorder often.
// References the real materials table — no invented fabric catalog.
// Not wired into OrderWizard/AI recommendation defaults yet — later task.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_fabric_preferences', function (Blueprint $table) {
            $table->id('preference_id');
            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')->references('user_id')->on('users')->onDelete('cascade');
            $table->unsignedBigInteger('material_id');
            $table->foreign('material_id')->references('material_id')->on('materials')->onDelete('cascade');
            $table->string('notes', 255)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'material_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_fabric_preferences');
    }
};
