<?php
// database/migrations/2026_06_08_000001_add_color_hex_to_purchase_orders.php
// TASK M — PO Color Swatch Matching
//
// Master Prompt: "ALTER TABLE purchase_orders ADD COLUMN color_hex VARCHAR(7) NULL"
// Interview (Ma'am Fe): When fabric arrives, staff compares physical tela
//   to OTG swatch. System must show ORDER color vs RECEIVED color.
//   Color mismatch (ΔE > 5) → alert manager + production hold.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            // Color ordered — from the originating order's color field
            $table->string('order_color_hex', 7)->nullable()->after('notes')
                  ->comment('Hex of color specified in the original order (PO swatch)');

            // Color received — staff selects when goods arrive
            $table->string('received_color_hex', 7)->nullable()->after('order_color_hex')
                  ->comment('Hex of fabric color actually received — set during MIGO MT-101 receipt');

            // Mismatch flag — set by receive() when ΔE > 5
            $table->boolean('color_mismatch')->default(false)->after('received_color_hex')
                  ->comment('True when received color differs from ordered color beyond tolerance');

            // Production hold — cutting cannot begin until swatch confirmed
            $table->boolean('color_confirmed')->default(false)->after('color_mismatch')
                  ->comment('True when staff/manager confirms received color is acceptable');

            $table->string('color_notes', 500)->nullable()->after('color_confirmed')
                  ->comment('Staff note on color match or reason for acceptance despite mismatch');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn([
                'order_color_hex',
                'received_color_hex',
                'color_mismatch',
                'color_confirmed',
                'color_notes',
            ]);
        });
    }
};
