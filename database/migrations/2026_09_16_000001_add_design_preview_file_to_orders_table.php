<?php
// database/migrations/2026_09_16_000001_add_design_preview_file_to_orders_table.php
//
// Design Studio's rendered preview PNG was being stored as a base64 data URL
// inside orders.studio_config (DesignStudio.jsx orderThis() -> previewPng).
// A 2x-multiplier canvas export is ~150-500KB of base64 per order, and
// OrderController::adminIndex() selects orders.* and json_decodes every row,
// so the paginated admin list was shipping ~20 of those blobs per page for an
// image that list never renders.
//
// This column holds a real file path on designRefDisk() instead, matching how
// client_design_ref_file already works. Legacy orders keep their inline
// previewPng and are still read correctly via the fallback in customerShow()
// and buildOrderDetail(), so no backfill is required.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'client_design_preview_file')) {
                $table->string('client_design_preview_file', 255)->nullable()
                      ->after('client_design_ref_file')
                      ->comment('Path to the Design Studio rendered preview PNG. Replaces the base64 previewPng previously embedded in studio_config.');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumnIfExists('client_design_preview_file');
        });
    }
};
