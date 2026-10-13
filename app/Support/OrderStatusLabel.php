<?php

namespace App\Support;

// Customer-facing order labels: one map for the dashboard funnel, emails and SMS. Status values themselves never change.
final class OrderStatusLabel
{
    public const MAP = [
        'pending'   => 'Pending',
        'confirmed' => 'Confirmed',
        'pattern'   => 'In Production', 'segregation' => 'In Production', 'cutting' => 'In Production', 'sewing' => 'In Production',
        'qc'        => 'In Production', 'pressing'    => 'In Production', 'packing' => 'In Production',
        'completed' => 'Delivered',
        'cancelled' => 'Cancelled',
    ];

    public static function for(string $status): string
    {
        return self::MAP[$status] ?? ucfirst($status);
    }

    // True when a move between two statuses changes what the customer sees (internal stage moves do not).
    public static function changes(string $from, string $to): bool
    {
        return self::for($from) !== self::for($to);
    }
}
