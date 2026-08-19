<?php
// database/migrations/2026_06_16_000001_create_order_drafts_table.php
//
// Task T — Design Studio draft persistence
//
// Stores per-user design studio saves so closing the browser tab
// does not lose work. One active draft per user (upserted on save).
// preview_dataurl stores the PNG thumbnail (base64, max ~200KB).
// studio_config stores the full JSON canvas state.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_drafts', function (Blueprint $table) {
            $table->id('draft_id');

            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')
                  ->references('user_id')
                  ->on('users')
                  ->onDelete('cascade');  // draft deleted when user deleted

            // Full canvas state: cfg (garment/sleeve/colors/patterns) +
            // frontOverlays + backOverlays (logos/text per face)
            $table->json('studio_config');

            // Base64 PNG thumbnail (~100–200KB). Nullable so saves work
            // even if canvas exportPNG() fails (WebGL context lost).
            $table->mediumText('preview_dataurl')->nullable();

            // Draft label for future "My Drafts" list feature
            $table->string('label', 120)->nullable();

            $table->timestamps(); // created_at, updated_at
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_drafts');
    }
};
