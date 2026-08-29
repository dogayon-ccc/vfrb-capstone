<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Adds a job-function sub-role for staff accounts, independent of the
// locked customer/staff/manager role. Managers and customers ignore this
// column entirely — it only gates staff nav + backend endpoints.
//
// 'general' = sees everything (default — matches today's behavior for
// all existing staff rows, so this migration is non-breaking).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('job_function', [
                'general',     // full staff access (unchanged behavior)
                'production',  // Production, Output Log, QC Checklist, Incidents
                'inventory',   // Inventory, Materials, Usage Rates, Physical Count, Procurement
                'sales',       // Orders, Transactions, Delivery, Messages
            ])->default('general')->after('client_type');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('job_function');
        });
    }
};
