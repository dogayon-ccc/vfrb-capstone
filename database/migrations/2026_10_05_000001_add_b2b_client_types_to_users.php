<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// 'individual' stays in the enum so existing rows remain valid; new signups can no longer pick it.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            return; // MySQL-only MODIFY; Postgres schema is managed separately.
        }

        DB::statement("ALTER TABLE users MODIFY client_type ENUM('individual','corporate','school','medical','government','organization') NULL DEFAULT NULL");
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            return; // MySQL-only MODIFY; Postgres schema is managed separately.
        }

        DB::table('users')->whereIn('client_type', ['government', 'organization'])->update(['client_type' => 'corporate']);
        DB::statement("ALTER TABLE users MODIFY client_type ENUM('individual','corporate','school','medical') NULL DEFAULT NULL");
    }
};
