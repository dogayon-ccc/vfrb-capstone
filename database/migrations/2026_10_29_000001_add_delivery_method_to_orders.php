<?php

// Customer's choice at ordering: VFRB delivers, or the customer picks up at VFRB. The delivery record inherits it.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('orders', 'delivery_method')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('delivery_method', 20)->default('vfrb_deliver')->after('delivery_address');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('orders', 'delivery_method')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('delivery_method');
            });
        }
    }
};
