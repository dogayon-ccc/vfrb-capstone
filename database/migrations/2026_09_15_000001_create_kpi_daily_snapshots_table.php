<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// One row per calendar day. Backs the Dashboard KPI sparklines — real
// history, not invented data. Captured lazily by AdminDashboardController
// (writes today's row on first dashboard load of the day) so it works
// without a running cron/scheduler.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpi_daily_snapshots', function (Blueprint $table) {
            $table->id('snapshot_id');
            $table->date('snapshot_date')->unique();
            $table->decimal('revenue', 12, 2)->default(0)->comment('sales_transactions.amount_paid for that day');
            $table->unsignedInteger('orders_total')->default(0)->comment('cumulative orders count as of that day');
            $table->unsignedInteger('in_production')->default(0)->comment('orders currently in the 7-stage pipeline that day');
            $table->decimal('stock_health_pct', 5, 2)->default(0)->comment('% materials with quantity_in_stock > reorder_threshold');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_daily_snapshots');
    }
};