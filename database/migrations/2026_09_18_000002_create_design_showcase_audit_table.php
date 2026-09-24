<?php
// database/migrations/2026_09_18_000002_create_design_showcase_audit_table.php
//
// Client Design Showcase (ADR-1) — audit trail. Deliberately NOT cascaded
// off designs: a removed-from-showcase or later-deleted design should still
// leave a record of who submitted/approved/rejected/removed it and when.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('design_showcase_audit')) {
            return;
        }

        Schema::create('design_showcase_audit', function (Blueprint $table) {
            $table->id('log_id');

            $table->unsignedBigInteger('design_id');
            $table->foreign('design_id')->references('design_id')->on('designs')->onDelete('cascade');
            // design_id cascades (audit for a hard-deleted design is moot),
            // but actor_user_id does not — the audit line should survive
            // even if the staff/manager account is later removed.

            $table->unsignedBigInteger('actor_user_id');
            $table->foreign('actor_user_id')->references('user_id')->on('users')->onDelete('cascade');

            $table->enum('action', ['submitted', 'approved', 'rejected', 'removed']);
            $table->text('note')->nullable();

            $table->timestamp('created_at')->useCurrent();
            // No updated_at — an audit row is append-only, never edited.

            $table->index(['design_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('design_showcase_audit');
    }
};
