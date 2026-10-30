<?php

// Indexes for the four hot reads found in the M15 EXPLAIN audit; each is guarded so reruns and Postgres are safe.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEXES = [
        'orders'                    => ['idx_orders_created' => ['created_at']],
        'order_messages'            => ['idx_om_read_order' => ['is_read', 'order_id']],
        'notifications'             => ['idx_notif_user_sent' => ['user_id', 'date_sent']],
        'order_production_tracking' => ['idx_opt_order_stage' => ['order_id', 'stage']],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $name => $columns) {
                if (! Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
                }
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach (array_keys($indexes) as $name) {
                if (Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
                }
            }
        }
    }
};
