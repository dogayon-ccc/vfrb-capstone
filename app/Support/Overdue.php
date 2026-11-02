<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

// What counts as overdue: shared by the daily digest flags and the dashboard's Needs attention count.
final class Overdue
{
    // Open orders past their date. Pending orders have no agreed date yet, so they use the customer's preferred date.
    public static function orders(): Builder
    {
        $today = BusinessDay::now()->toDateString();

        return DB::table('orders')
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->where(fn ($q) => $q
                ->where(fn ($p) => $p->where('status', 'pending')->where('target_delivery_date', '<', $today))
                ->orWhere(fn ($a) => $a->where('status', '!=', 'pending')->whereRaw('COALESCE(promised_date, target_delivery_date) < ?', [$today])));
    }

    public const OPEN_STATUSES = ['pending', 'confirmed', 'pattern', 'segregation', 'cutting', 'sewing', 'qc', 'pressing', 'packing', 'for_delivery'];

    // Open orders due between today and $days from now (agreed date first, preferred date as fallback); `due_on` is selected.
    public static function dueSoon(int $days = 7): Builder
    {
        return DB::table('orders')
            ->selectRaw('orders.*, COALESCE(promised_date, target_delivery_date) as due_on')
            ->whereIn('status', self::OPEN_STATUSES)
            ->whereRaw('COALESCE(promised_date, target_delivery_date) BETWEEN ? AND ?', [BusinessDay::now()->toDateString(), BusinessDay::now()->addDays($days)->toDateString()]);
    }

    // Issued, unpaid invoices past their due date.
    public static function invoices(): Builder
    {
        return DB::table('invoices')->where('status', 'issued')->whereNotNull('due_date')->where('due_date', '<', BusinessDay::now()->toDateString());
    }
}
