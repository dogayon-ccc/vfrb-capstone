<?php
// purchase_orders.items was a JSON-encoded array in a longtext column — no
// material_id FK, no SQL aggregation, total_amount computed once in PHP and
// never re-derivable. This splits it into purchase_order_items, backfills
// from the existing JSON, then drops the column.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id('po_item_id');
            $table->unsignedBigInteger('po_id');
            $table->unsignedBigInteger('material_id');
            $table->decimal('qty', 12, 2);
            $table->decimal('unit_cost', 10, 2);
            $table->timestamps();

            $table->foreign('po_id')->references('po_id')->on('purchase_orders')->cascadeOnDelete();
            $table->foreign('material_id')->references('material_id')->on('materials');
            $table->index('po_id');
        });

        if (Schema::hasColumn('purchase_orders', 'items')) {
            $now = now();
            DB::table('purchase_orders')->select('po_id', 'items')->orderBy('po_id')
                ->chunk(100, function ($rows) use ($now) {
                    foreach ($rows as $po) {
                        $items = json_decode($po->items ?? '', true);
                        if (!is_array($items)) continue;

                        foreach ($items as $item) {
                            if (empty($item['material_id']) || empty($item['qty'])) continue;
                            DB::table('purchase_order_items')->insert([
                                'po_id'       => $po->po_id,
                                'material_id' => $item['material_id'],
                                'qty'         => $item['qty'],
                                'unit_cost'   => $item['unit_cost'] ?? 0,
                                'created_at'  => $now,
                                'updated_at'  => $now,
                            ]);
                        }
                    }
                });

            Schema::table('purchase_orders', function (Blueprint $table) {
                $table->dropColumn('items');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('purchase_orders', 'items')) {
            Schema::table('purchase_orders', function (Blueprint $table) {
                $table->longText('items')->nullable()->after('order_id');
            });

            $rows = DB::table('purchase_order_items')->orderBy('po_id')->get()->groupBy('po_id');
            foreach ($rows as $poId => $items) {
                $encoded = $items->map(fn($i) => [
                    'material_id' => $i->material_id,
                    'qty'         => (float) $i->qty,
                    'unit_cost'   => (float) $i->unit_cost,
                ])->values()->all();
                DB::table('purchase_orders')->where('po_id', $poId)
                    ->update(['items' => json_encode($encoded)]);
            }
        }

        Schema::dropIfExists('purchase_order_items');
    }
};
