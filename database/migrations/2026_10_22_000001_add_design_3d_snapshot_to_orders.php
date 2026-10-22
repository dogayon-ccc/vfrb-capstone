<?php
// Optional customer-taken 3D (approx.) snapshot PNG, stored next to the 2D design preview.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('orders', 'client_design_3d_snapshot_file')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('client_design_3d_snapshot_file')->nullable()->after('client_design_preview_file');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('orders', 'client_design_3d_snapshot_file')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('client_design_3d_snapshot_file');
            });
        }
    }
};
