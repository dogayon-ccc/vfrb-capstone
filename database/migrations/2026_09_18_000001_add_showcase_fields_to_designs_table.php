<?php
// database/migrations/2026_09_18_000001_add_showcase_fields_to_designs_table.php
//
// Client Design Showcase (ADR-1). Purely additive — every existing row gets
// showcase_status='none' via column default, so running this can never make
// an existing client's design visible to anyone else.
//
// submitted_by_user_id is captured explicitly at submission time and is
// NOT derived from designs.orders() (hasMany Order via design_id) the way
// the private-gallery ownership check is — that join answers "does any
// order pointing at this design_id belong to me", which is fine for a
// read-only ownership check but the wrong foundation for consent: this
// column is the one place that records who actually submitted the design.
//
// FKs reference user_id (not id) — vfrb_db.users PRIMARY KEY = user_id,
// same convention already fixed in 2026_04_21_000002.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('designs', function (Blueprint $table) {
            if (!Schema::hasColumn('designs', 'showcase_status')) {
                $table->enum('showcase_status', ['none', 'submitted', 'approved', 'rejected', 'removed'])
                      ->default('none')
                      ->after('is_active')
                      ->comment('Cross-client showcase moderation state. none = private only.');
            }
            if (!Schema::hasColumn('designs', 'showcase_label')) {
                $table->string('showcase_label', 80)->nullable()->after('showcase_status')
                      ->comment('Anonymized display name shown in the cross-client showcase, set on approval.');
            }
            if (!Schema::hasColumn('designs', 'submitted_by_user_id')) {
                $table->unsignedBigInteger('submitted_by_user_id')->nullable()->after('showcase_label');
                $table->foreign('submitted_by_user_id')
                      ->references('user_id')->on('users')->onDelete('set null');
            }
            if (!Schema::hasColumn('designs', 'showcase_approved_by')) {
                $table->unsignedBigInteger('showcase_approved_by')->nullable()->after('submitted_by_user_id');
                $table->foreign('showcase_approved_by')
                      ->references('user_id')->on('users')->onDelete('set null');
            }
            if (!Schema::hasColumn('designs', 'showcase_approved_at')) {
                $table->timestamp('showcase_approved_at')->nullable()->after('showcase_approved_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('designs', function (Blueprint $table) {
            if (Schema::hasColumn('designs', 'submitted_by_user_id')) {
                $table->dropForeign(['submitted_by_user_id']);
            }
            if (Schema::hasColumn('designs', 'showcase_approved_by')) {
                $table->dropForeign(['showcase_approved_by']);
            }
            $table->dropColumnIfExists('showcase_status');
            $table->dropColumnIfExists('showcase_label');
            $table->dropColumnIfExists('submitted_by_user_id');
            $table->dropColumnIfExists('showcase_approved_by');
            $table->dropColumnIfExists('showcase_approved_at');
        });
    }
};
