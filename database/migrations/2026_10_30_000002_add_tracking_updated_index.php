<?php

// The dashboard's delayed-production list filters and sorts on updated_at; idx_opt_order_stage stays for the (order_id, stage) joins.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasIndex('order_production_tracking', 'idx_opt_updated')) {
            Schema::table('order_production_tracking', fn (Blueprint $t) => $t->index('updated_at', 'idx_opt_updated'));
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('order_production_tracking', 'idx_opt_updated')) {
            Schema::table('order_production_tracking', fn (Blueprint $t) => $t->dropIndex('idx_opt_updated'));
        }
    }
};
