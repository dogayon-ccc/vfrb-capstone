<?php
// Client-supplied materials (VFRB is a subcontractor): most fabric is owned by the client
// (e.g. OTG), held by VFRB only for that order, and leftovers go back to the client.
//   materials.ownership    vfrb   = VFRB buys and values it (thread, logo patches)
//                          client = client-owned; tracked per order in quantity only, never valued
//   client_material_ledger one row per movement of client material for one order:
//                          received (from client) → issued (at Cutting) → wastage / returned
//                          balance = received − issued − wastage − returned, must be 0 before delivery.
// Fabric is backfilled to 'client' (Ma'am Rox: VFRB owns only thread and logo patches); the
// manager can reclassify any material in Materials master data.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('materials', 'ownership')) {
            Schema::table('materials', function (Blueprint $table) {
                $table->enum('ownership', ['vfrb', 'client'])->default('vfrb')->after('category');
            });
            DB::table('materials')->where('category', 'Fabric')->update(['ownership' => 'client']);
        }

        if (! Schema::hasTable('client_material_ledger')) {
            Schema::create('client_material_ledger', function (Blueprint $table) {
                $table->id('entry_id');
                $table->unsignedBigInteger('order_id');
                $table->foreign('order_id')->references('order_id')->on('orders');
                $table->unsignedBigInteger('material_id');
                $table->foreign('material_id')->references('material_id')->on('materials');
                $table->enum('type', ['received', 'issued', 'wastage', 'returned']);
                $table->decimal('qty', 12, 4); // always positive; type gives the direction
                $table->string('note', 255)->nullable();
                $table->unsignedBigInteger('recorded_by');
                $table->foreign('recorded_by')->references('user_id')->on('users');
                $table->timestamps();
                $table->index(['order_id', 'material_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('client_material_ledger');
        if (Schema::hasColumn('materials', 'ownership')) {
            Schema::table('materials', function (Blueprint $table) {
                $table->dropColumn('ownership');
            });
        }
    }
};
