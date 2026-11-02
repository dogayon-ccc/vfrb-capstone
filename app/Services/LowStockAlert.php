<?php

namespace App\Services;

use App\Notifications\PortalMailNotification;
use App\Support\EmailNotify;
use Illuminate\Support\Facades\DB;

// Instant low-stock alert to managers and staff, in-app and by email, at most once per material per 24 hours.
// Called after a stock movement (Inventory stock-out/adjust, Cutting issue) leaves a VFRB-owned material at or below its reorder level.
final class LowStockAlert
{
    public function notify(string $materialName, float $stock, float $threshold, string $unit): void
    {
        $recentlyNotified = DB::table('notifications')
            ->where('type', 'low_stock')
            ->where('message', 'like', addcslashes($materialName, '\\%_').' stock (%') // full name, so "Thread Red" does not mute "Thread"
            ->where('date_sent', '>=', now()->subDay())
            ->exists();
        if ($recentlyNotified) {
            return;
        }

        $users = DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->whereIn('roles.name', ['manager', 'staff'])
            ->pluck('model_has_roles.model_id');

        $message = "{$materialName} stock ({$stock} {$unit}) is below reorder threshold ({$threshold} {$unit}).";
        $now = now();
        $rows = $users->map(fn ($userId) => [
            'user_id' => $userId, 'order_id' => null, 'message' => $message, 'type' => 'low_stock', 'title' => 'Low Stock Alert',
            'is_read' => 0, 'date_sent' => $now, 'created_at' => $now, 'updated_at' => $now,
        ])->all();
        if ($rows) {
            DB::table('notifications')->insert($rows);
        }

        EmailNotify::users($users, new PortalMailNotification(
            "Low stock: {$materialName}", [$message, 'Reorder or record incoming stock to clear this alert.'], 'Open inventory', '/admin/inventory'));
    }
}
