<?php
// database/migrations/2026_07_22_000001_add_auto_generated_to_rfq_requests.php
// Automation feature — "auto-suggest RFQ drafts when a material crosses
// reorder threshold (staff still approves/sends)".
//
// rfq_requests.created_by is required by rfqIndex()'s INNER JOIN on users,
// so a system-generated RFQ still needs a real user_id — this column is
// what lets the UI show "🤖 Auto-suggested" instead of a staff name badge,
// without needing a nullable created_by (which would break that join).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rfq_requests', function (Blueprint $table) {
            $table->boolean('auto_generated')->default(false)->after('created_by')
                  ->comment('True when created by the daily automation job (low-stock threshold), not a staff member');
        });
    }

    public function down(): void
    {
        Schema::table('rfq_requests', function (Blueprint $table) {
            $table->dropColumn('auto_generated');
        });
    }
};
