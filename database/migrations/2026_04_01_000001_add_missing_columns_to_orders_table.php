<?php
// database/migrations/2026_04_01_000001_add_missing_columns_to_orders_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'sizing_type')) {
                $table->enum('sizing_type', ['standard', 'custom'])
                    ->default('standard')->after('quantity_ordered');
            }
            if (!Schema::hasColumn('orders', 'target_delivery_date')) {
                $table->date('target_delivery_date')->nullable()->after('sizing_type')
                    ->comment('Customer-requested delivery date. May be negotiated by VFRB.');
            }
            if (!Schema::hasColumn('orders', 'negotiated_delivery_date')) {
                $table->date('negotiated_delivery_date')->nullable()->after('target_delivery_date')
                    ->comment('VFRB-proposed date after capacity check. NULL = original date accepted.');
            }
            if (!Schema::hasColumn('orders', 'discount_pct')) {
                $table->decimal('discount_pct', 5, 2)->default(0)->after('negotiated_delivery_date')
                    ->comment('Bulk discount percentage. 5% ≥50pcs, 10% ≥100pcs, 15% ≥200pcs.');
            }
            if (!Schema::hasColumn('orders', 'payment_terms')) {
                $table->enum('payment_terms', ['full', 'down', 'net_30', 'net_60'])
                    ->default('full')->after('discount_pct');
            }
            if (!Schema::hasColumn('orders', 'payment_method')) {
                $table->enum('payment_method', ['cash', 'gcash', 'ewallet', 'bank_transfer'])
                    ->nullable()->after('payment_terms');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumnIfExists('sizing_type');
            $table->dropColumnIfExists('target_delivery_date');
            $table->dropColumnIfExists('negotiated_delivery_date');
            $table->dropColumnIfExists('discount_pct');
            $table->dropColumnIfExists('payment_terms');
            $table->dropColumnIfExists('payment_method');
        });
    }
};