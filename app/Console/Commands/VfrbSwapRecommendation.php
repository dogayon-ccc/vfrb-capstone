<?php

namespace App\Console\Commands;

use App\Models\Material;
use App\Models\Order;
use App\Services\MaterialCatalogMatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Replaces recommended materials on one order, e.g. a fabric whose colour does not match the design.
 *   php artisan vfrb:swap-recommendation 22 55:68 69:73 --dry-run
 * Each swap keeps the row (and the customer's acceptance) and marks it 'modified'. Refuses rows already issued to
 * production (the stock ledger records them), targets the colour/garment filter would not allow, and reruns.
 */
class VfrbSwapRecommendation extends Command
{
    protected $signature = 'vfrb:swap-recommendation {order : order_id} {swaps* : from_material_id:to_material_id}
                            {--dry-run : Print what would be written, then roll back}';

    protected $description = 'Swap recommended materials on an order for catalog items that fit the design';

    public function handle(MaterialCatalogMatcher $matcher): int
    {
        $dry   = (bool) $this->option('dry-run');
        $order = Order::find((int) $this->argument('order'));
        if (!$order) {
            $this->error('Order not found.');
            return self::FAILURE;
        }

        $eligible = $matcher->eligible(Material::all()->map->toArray()->all(), $order->garmentName(), $order->designColours());
        $plan = [];
        foreach ($this->argument('swaps') as $pair) {
            if (!preg_match('/^(\d+):(\d+)$/', $pair, $p)) {
                $this->error("Bad swap '{$pair}'; use from_id:to_id.");
                return self::FAILURE;
            }
            [$from, $to] = [(int) $p[1], (int) $p[2]];
            $rec = DB::table('material_recommendations')->where('order_id', $order->order_id)->where('material_id', $from)->first();
            $new = $eligible[$to] ?? null;

            $why = match (true) {
                !$rec                       => "material {$from} is not on order #{$order->order_id} (already swapped?)",
                $rec->issued_at !== null    => "rec {$rec->rec_id} was issued to production on {$rec->issued_at}; the stock ledger records it",
                !$new                       => "material {$to} does not fit this order's garment/colour (or is not AI-eligible)",
                $new['category'] !== $rec->category => "material {$to} is {$new['category']}, not {$rec->category}",
                DB::table('material_recommendations')->where('order_id', $order->order_id)->where('material_id', $to)->exists()
                                            => "material {$to} is already on the order",
                default                     => null,
            };
            if ($why) {
                $this->error("Refusing {$pair}: {$why}. Nothing written.");
                return self::FAILURE;
            }
            $plan[] = [$rec, $new];
        }

        $before = DB::table('material_recommendations')->where('order_id', $order->order_id)->get();
        if (!$dry) {
            $path = 'backups/material_recommendations-order' . $order->order_id . '-' . now()->format('Ymd-His') . '.json';
            if (!Storage::disk('local')->put($path, $before->toJson(JSON_PRETTY_PRINT))) {
                $this->error('Backup failed; nothing written.');
                return self::FAILURE;
            }
            $this->line("Backup: storage/app/private/{$path} ({$before->count()} rows)");
        }

        DB::beginTransaction();
        try {
            foreach ($plan as [$rec, $new]) {
                DB::table('material_recommendations')->where('rec_id', $rec->rec_id)->update([
                    'material_id'   => $new['material_id'],
                    'material_name' => $new['material_name'],
                    'category'      => $new['category'],
                    'unit'          => $new['unit'],
                    'status'        => 'modified',
                    'ai_note'       => "Replaced {$rec->material_name}: its colour did not match the design.",
                    'updated_at'    => now(),
                ]);
                $this->line(($dry ? '[dry-run] ' : '') . "rec {$rec->rec_id}: {$rec->material_name} -> {$new['material_name']} (status modified)");
            }
            $dry ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Failed, rolled back: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->info(($dry ? 'Dry run rolled back. ' : 'Done. ') . count($plan) . " row(s) on order #{$order->order_id}; rows before/after: {$before->count()}/"
            . DB::table('material_recommendations')->where('order_id', $order->order_id)->count());
        return self::SUCCESS;
    }
}
