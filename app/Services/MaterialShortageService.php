<?php

// Staff-facing stock warnings for an order's recommended material types. Names and reasons only; never shown to customers.

namespace App\Services;

use Illuminate\Support\Facades\DB;

class MaterialShortageService
{
    /** @return list<array{material_id:int, material_name:string, category:?string, reason:string}> */
    public function forOrder(int $orderId): array
    {
        return DB::table('material_recommendations')
            ->join('materials', 'material_recommendations.material_id', '=', 'materials.material_id')
            ->where('material_recommendations.order_id', $orderId)
            ->where('material_recommendations.status', '!=', 'rejected')
            ->whereRaw('materials.quantity_in_stock <= materials.reorder_threshold')
            // Client-supplied material isn't VFRB stock; its supply is checked by ClientMaterialService.
            ->when(ClientMaterialService::ready(), fn ($q) => $q->where('materials.ownership', 'vfrb'))
            ->select('materials.material_id', 'materials.material_name', 'materials.category', 'materials.quantity_in_stock')
            ->distinct()
            ->orderBy('materials.material_name')
            ->get()
            ->map(fn ($m) => [
                'material_id' => (int) $m->material_id,
                'material_name' => $m->material_name,
                'category' => $m->category,
                'reason' => (float) $m->quantity_in_stock <= 0 ? 'out_of_stock' : 'low_stock',
            ])
            ->all();
    }

    /** One-line summary for manager notifications, or null when nothing is short. */
    public function summary(int $orderId): ?string
    {
        $warnings = $this->forOrder($orderId);
        if (! $warnings) {
            return null;
        }

        return 'Stock check: '.collect($warnings)
            ->map(fn ($w) => $w['material_name'].' ('.($w['reason'] === 'out_of_stock' ? 'out of stock' : 'low stock').')')
            ->implode(', ').'.';
    }
}
