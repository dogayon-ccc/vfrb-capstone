<?php
// database/migrations/2026_08_09_000001_add_qty_to_measurements_table.php
//
// AUDIT FINDING: measurements table stores size_label (e.g. "L") for
// standard-sizing orders but has NO quantity column — OrderController::
// customerStore() decodes the sizes JSON ({size => qty}) and inserts one
// row per size, but never stores $qty anywhere. Every order's per-size
// quantity breakdown (visibly collected in OrderWizard's Sizing step,
// e.g. "L: 100 pcs") was being silently discarded on save.
//
// Nullable — only meaningful for type='standard' rows; type='custom' rows
// (body measurements) don't use it.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('measurements', function (Blueprint $table) {
            $table->unsignedInteger('qty')->nullable()->after('size_label');
        });
    }

    public function down(): void
    {
        Schema::table('measurements', function (Blueprint $table) {
            $table->dropColumn('qty');
        });
    }
};
