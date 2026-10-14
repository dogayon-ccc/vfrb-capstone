<?php
// Insert-only audit trail: login, logout, failed login and key writes.
// Read by manager and owner only (GET /api/admin/audit-logs).
// UPDATE/DELETE (and TRUNCATE on Postgres) are blocked by triggers, so rows
// can't be edited through the app. A database superuser can still drop the
// triggers, so this is not a tamper-proof ledger.
// Failed logins never store the typed username/email: an unknown login name
// is recorded as user_id NULL plus the IP only.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('audit_logs')) {
            return;
        }

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id('audit_id');

            // No FK: the row must outlive the user, and an ON DELETE SET NULL
            // would be an UPDATE, which the trigger blocks (so the user
            // delete would fail). Null = failed login for an unknown login name.
            $table->unsignedBigInteger('user_id')->nullable();

            $table->string('action', 50);              // login, logout, login_failed, order_status_changed, ...
            $table->string('entity', 50)->nullable();  // orders, invoices, users, ...
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->ipAddress('ip')->nullable();       // inet on pgsql, varchar(45) on mysql

            $table->timestamp('created_at')->useCurrent();
            // No updated_at: rows are never edited.

            $table->index(['user_id', 'created_at']);
            $table->index(['action', 'created_at']);
            $table->index(['entity', 'entity_id']);
        });

        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION audit_logs_block_mutation() RETURNS trigger
                LANGUAGE plpgsql AS $$
                BEGIN
                    RAISE EXCEPTION 'audit_logs is insert-only';
                END;
                $$;
                CREATE TRIGGER audit_logs_no_update_delete
                    BEFORE UPDATE OR DELETE ON audit_logs
                    FOR EACH ROW EXECUTE FUNCTION audit_logs_block_mutation();
                CREATE TRIGGER audit_logs_no_truncate
                    BEFORE TRUNCATE ON audit_logs
                    FOR EACH STATEMENT EXECUTE FUNCTION audit_logs_block_mutation();
            SQL);
        } elseif ($driver === 'mysql') {
            DB::unprepared("CREATE TRIGGER audit_logs_no_update BEFORE UPDATE ON audit_logs
                FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is insert-only'");
            DB::unprepared("CREATE TRIGGER audit_logs_no_delete BEFORE DELETE ON audit_logs
                FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is insert-only'");
        }
    }

    public function down(): void
    {
        // Rolling back would destroy the audit trail, so refuse once it has rows.
        if (Schema::hasTable('audit_logs') && DB::table('audit_logs')->exists()) {
            throw new RuntimeException('audit_logs has rows; refusing to drop it.');
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TABLE IF EXISTS audit_logs; DROP FUNCTION IF EXISTS audit_logs_block_mutation();');
            return;
        }

        Schema::dropIfExists('audit_logs'); // MySQL drops the triggers with the table
    }
};
