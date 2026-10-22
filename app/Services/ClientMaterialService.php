<?php
// Client-supplied material ledger (see migration 2026_10_21_000001_add_client_material_ledger).
// One place for the rules shared by ProductionStageService (Cutting gate + issue),
// ProductionController (manager override), DeliveryController (closure) and ClientMaterialController.
//
// Per order and client-owned material:
//   required  = consumption staff entered at Pattern (material_recommendations.actual_qty_issued)
//   on hand   = received − issued − wastage − returned
//   short     = before Cutting issues it: required − (received − wastage − returned), never negative

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ClientMaterialService
{
    public const TYPES = ['received', 'issued', 'wastage', 'returned'];
    private const EPS = 0.0001;
    private static ?bool $ready = null;

    // False until the migration has run: callers then keep the pre-ledger behaviour (no gates).
    public static function ready(): bool
    {
        return self::$ready ??= \Illuminate\Support\Facades\Cache::get('client_ledger_ready:' . \Illuminate\Support\Facades\DB::connection()->getDatabaseName())
            || ((Schema::hasTable('client_material_ledger') && Schema::hasColumn('materials', 'ownership'))
                && \Illuminate\Support\Facades\Cache::forever('client_ledger_ready:' . \Illuminate\Support\Facades\DB::connection()->getDatabaseName(), true));
    }

    // Low-stock rule for VFRB stock only: client-supplied material is never reordered by VFRB.
    public static function lowStockSql(string $table = ''): string
    {
        $p = $table !== '' ? "{$table}." : '';
        return "{$p}quantity_in_stock <= {$p}reorder_threshold" . (self::ready() ? " AND {$p}ownership = 'vfrb'" : '');
    }

    public static function isClientOwned(object $material): bool
    {
        return self::ready() && ($material->ownership ?? 'vfrb') === 'client';
    }

    // Every client-owned material the order needs or has moved, with its running balance.
    public function summary(int $orderId): array
    {
        if (! self::ready()) {
            return [];
        }
        $recs = DB::table('material_recommendations as r')
            ->join('materials as m', 'm.material_id', '=', 'r.material_id')
            ->where('r.order_id', $orderId)
            ->where('r.customer_accepted', 1)
            ->where('m.ownership', 'client')
            ->get(['r.material_id', 'm.material_name', 'm.unit', 'r.actual_qty_issued', 'r.issued_at'])
            ->keyBy('material_id');

        $sums = DB::table('client_material_ledger')
            ->where('order_id', $orderId)
            ->groupBy('material_id', 'type')
            ->get(['material_id', 'type', DB::raw('SUM(qty) as qty')]);

        $ids = $recs->keys()->merge($sums->pluck('material_id'))->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }
        $mats = DB::table('materials')->whereIn('material_id', $ids)->get(['material_id', 'material_name', 'unit'])->keyBy('material_id');

        $lines = [];
        foreach ($ids as $id) {
            $t = fn (string $type) => (float) ($sums->first(fn ($s) => (int) $s->material_id === (int) $id && $s->type === $type)->qty ?? 0);
            $rec      = $recs->get($id);
            $received = $t('received');
            $issued   = $t('issued');
            $wastage  = $t('wastage');
            $returned = $t('returned');
            $required = $rec && $rec->actual_qty_issued !== null ? (float) $rec->actual_qty_issued : null;
            $isIssued = $issued > 0 || ($rec && $rec->issued_at !== null);
            $short    = ! $isIssued && $required !== null ? max(0, $required - ($received - $wastage - $returned)) : 0;

            $lines[] = [
                'material_id'   => (int) $id,
                'material_name' => $mats[$id]->material_name ?? $rec->material_name ?? "#{$id}",
                'unit'          => $mats[$id]->unit ?? $rec->unit ?? '',
                'required'      => $required,
                'received'      => round($received, 4),
                'issued'        => round($issued, 4),
                'wastage'       => round($wastage, 4),
                'returned'      => round($returned, 4),
                'on_hand'       => round($received - $issued - $wastage - $returned, 4),
                'short'         => round($short, 4),
                'issued_at_cutting' => $isIssued,
            ];
        }

        return $lines;
    }

    // Lines still short of what Pattern says Cutting needs. Empty = Cutting may proceed.
    public function shortages(int $orderId): array
    {
        return array_values(array_filter($this->summary($orderId), fn ($l) => $l['short'] > self::EPS));
    }

    // Lines whose balance is not zero: leftovers to return, or more issued than received.
    public function openBalances(int $orderId): array
    {
        return array_values(array_filter($this->summary($orderId), fn ($l) => abs($l['on_hand']) > self::EPS));
    }

    public static function describe(array $lines, string $key): string
    {
        return implode(', ', array_map(fn ($l) => "{$l['material_name']} (" . rtrim(rtrim(number_format(abs($l[$key]), 2, '.', ''), '0'), '.') . " {$l['unit']})", $lines));
    }

    public function shortageMessage(int $orderId, array $short): string
    {
        return "Order #{$orderId} can't start Cutting: client-supplied materials are short — " . self::describe($short, 'short')
            . '. Record what the client delivered (Order → Client materials), or contact the client about the delay.';
    }

    public function closureBlocker(int $orderId): ?string
    {
        $open = $this->openBalances($orderId);
        if (! $open) {
            return null;
        }
        $over = array_filter($open, fn ($l) => $l['on_hand'] < 0);
        return $over
            ? "Order #{$orderId}: more client material was used than received — " . self::describe($over, 'on_hand') . '. Record the missing receipt before marking it delivered.'
            : "Order #{$orderId} still holds client materials — " . self::describe($open, 'on_hand') . '. Return the leftovers to the client (or record wastage) before marking it delivered.';
    }

    // Writes one movement. Returns an error message, or null when recorded.
    public function record(int $orderId, int $materialId, string $type, float $qty, ?string $note, int $actorId): ?string
    {
        if (! self::ready()) {
            return 'Client material tracking is not set up yet (database migration pending).';
        }
        if (! in_array($type, self::TYPES, true) || $qty <= 0) {
            return 'Enter a quantity above zero.';
        }

        return DB::transaction(function () use ($orderId, $materialId, $type, $qty, $note, $actorId) {
            $order = DB::table('orders')->where('order_id', $orderId)->lockForUpdate()->first(['order_id', 'status']);
            if (! $order) {
                return 'Order not found.';
            }
            if ($order->status === 'completed') {
                return "Order #{$orderId} is completed; its client materials are closed.";
            }
            if ($order->status === 'cancelled' && $type !== 'returned') {
                return "Order #{$orderId} is cancelled; only returns to the client can be recorded.";
            }

            $material = DB::table('materials')->where('material_id', $materialId)->first(['material_id', 'material_name', 'unit', 'ownership']);
            if (! $material || $material->ownership !== 'client') {
                return 'Only client-supplied materials are tracked here. VFRB-owned stock moves in Inventory.';
            }

            if (in_array($type, ['wastage', 'returned'], true)) {
                $line = collect($this->summary($orderId))->firstWhere('material_id', $materialId);
                $onHand = (float) ($line['on_hand'] ?? 0);
                if ($qty - $onHand > self::EPS) {
                    $fmt = rtrim(rtrim(number_format(max(0, $onHand), 2, '.', ''), '0'), '.');
                    return "Only {$fmt} {$material->unit} of {$material->material_name} is held for order #{$orderId}.";
                }
            }

            $this->insert($orderId, $materialId, $type, $qty, $note, $actorId);
            return null;
        });
    }

    // Raw insert, used by the Cutting issue inside its own transaction.
    public function insert(int $orderId, int $materialId, string $type, float $qty, ?string $note, int $actorId): void
    {
        DB::table('client_material_ledger')->insert([
            'order_id'    => $orderId,
            'material_id' => $materialId,
            'type'        => $type,
            'qty'         => $qty,
            'note'        => $note !== null ? mb_substr($note, 0, 255) : null,
            'recorded_by' => $actorId,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    // Orders with client material to receive, hold or return. Completed orders are closed at zero, so they drop out;
    // cancelled orders stay while anything is still held for return.
    public function openOrders(): array
    {
        if (! self::ready()) {
            return [];
        }
        $ids = DB::table('material_recommendations as r')
            ->join('materials as m', 'm.material_id', '=', 'r.material_id')
            ->where('r.customer_accepted', 1)->where('m.ownership', 'client')
            ->pluck('r.order_id')
            ->merge(DB::table('client_material_ledger')->pluck('order_id'))
            ->unique();

        $orders = DB::table('orders as o')->join('users as u', 'u.user_id', '=', 'o.user_id')
            ->whereIn('o.order_id', $ids)->where('o.status', '!=', 'completed')
            ->orderBy('o.order_id')
            ->get(['o.order_id', 'o.status', 'o.garment_type', 'o.target_delivery_date', 'u.name as customer_name', 'u.organization_name']);

        $out = [];
        foreach ($orders as $o) {
            $lines = $this->summary((int) $o->order_id);
            if ($o->status === 'cancelled' && ! array_filter($lines, fn ($l) => abs($l['on_hand']) > self::EPS)) {
                continue;
            }
            $out[] = [...(array) $o, 'lines' => $lines,
                'short'   => array_values(array_filter($lines, fn ($l) => $l['short'] > self::EPS)),
                'pending' => array_values(array_filter($lines, fn ($l) => $l['required'] === null && $l['received'] <= self::EPS)),
            ];
        }

        return $out;
    }

    public function entries(int $orderId): array
    {
        if (! self::ready()) {
            return [];
        }
        return DB::table('client_material_ledger as l')
            ->join('materials as m', 'm.material_id', '=', 'l.material_id')
            ->join('users as u', 'u.user_id', '=', 'l.recorded_by')
            ->where('l.order_id', $orderId)
            ->orderByDesc('l.created_at')->orderByDesc('l.entry_id')
            ->get(['l.entry_id', 'l.material_id', 'm.material_name', 'm.unit', 'l.type', 'l.qty', 'l.note', 'l.created_at', 'u.name as recorded_by_name'])
            ->map(fn ($e) => [...(array) $e, 'qty' => (float) $e->qty])
            ->all();
    }
}
