<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Notifications\OrderStatusNotification;
use Illuminate\Support\Facades\DB;

// Design Studio uniform set: each piece is its own order, linked by the shared studio_config.uniformSet.id.
final class StudioSet
{
    // The set id stored on an order, or null for a single-garment order.
    public static function setId(object $order): ?string
    {
        $cfg = is_array($order->studio_config ?? null) ? $order->studio_config : json_decode($order->studio_config ?? '', true);
        $id  = $cfg['uniformSet']['id'] ?? null;
        return is_string($id) && $id !== '' ? $id : null;
    }

    // Other orders of the same customer that belong to this order's set, excluding cancelled ones.
    public static function siblingIds(int $orderId): array
    {
        $order = DB::table('orders')->where('order_id', $orderId)->first(['order_id', 'user_id', 'studio_config']);
        $setId = $order ? self::setId($order) : null;
        if (!$setId) return [];

        return DB::table('orders')
            ->where('user_id', $order->user_id)
            ->where('order_id', '!=', $orderId)
            ->where('status', '!=', 'cancelled')
            ->whereNotNull('studio_config')
            ->get(['order_id', 'studio_config'])
            ->filter(fn ($o) => self::setId($o) === $setId)
            ->pluck('order_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    // Confirms the still-pending pieces of this order's set, with the same notification and email as a single confirm.
    public static function confirmPendingSiblings(int $orderId): array
    {
        $confirmed = [];
        foreach (self::siblingIds($orderId) as $id) {
            $userId = DB::table('orders')->where('order_id', $id)->where('status', 'pending')->value('user_id');
            if (!$userId) continue;

            DB::table('orders')->where('order_id', $id)->update(['status' => 'confirmed', 'updated_at' => now()]);
            AuditLog::record('order_status_changed', 'orders', $id);
            DB::table('notifications')->insert([
                'user_id'    => $userId,
                'order_id'   => $id,
                'message'    => "Your order #{$id} has been confirmed together with order #{$orderId} (same uniform set). Production will begin soon.",
                'type'       => 'order',
                'is_read'    => 0,
                'date_sent'  => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            EmailNotify::users([$userId], new OrderStatusNotification($id, 'confirmed'));
            $confirmed[] = $id;
        }
        return $confirmed;
    }
}
