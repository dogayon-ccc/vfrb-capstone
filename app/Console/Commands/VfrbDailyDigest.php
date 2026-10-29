<?php

// app/Console/Commands/VfrbDailyDigest.php
// AUTOMATION: three features, one command, callable manually or on a schedule.
//
//   1. Low-stock digest   → one consolidated notification/day (not N spam rows)
//   2. Auto-suggest RFQ   → creates an 'open' rfq_request for any material
//                           below threshold that doesn't already have one open
//                           (auto_generated=1 so staff can tell it apart —
//                           they still review/respond/close/convert it exactly
//                           like a manually created RFQ; nothing is auto-sent)
//   3. Deadline reminders → notifies customer + staff once per order when an
//                           active order's target_delivery_date is within 7
//                           days (checked once — no daily re-nagging)
//   4. Client supply      → one notification/day listing orders whose client-supplied
//                           materials are short or not delivered, so VFRB contacts the client
//   5. Weekly count       → Monday reminder to count VFRB-owned stock; later in the week only
//                           while materials are still uncounted
// Low stock and RFQs cover VFRB-owned materials only (ClientMaterialService::lowStockSql()).
//
// Run manually:   php artisan vfrb:daily-digest
// Run on schedule: registered in routes/console.php
// Also callable from the API via POST /api/admin/automation/run-digest
// (AutomationController@run) for an on-demand "Run Now" button — this
// command is the single source of truth either way, so manual and
// scheduled runs can never drift out of sync.

namespace App\Console\Commands;

use App\Http\Controllers\Api\PhysicalCountController;
use App\Services\ClientMaterialService;
use App\Support\OrderStatusLabel;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class VfrbDailyDigest extends Command
{
    protected $signature = 'vfrb:daily-digest';

    protected $description = 'Low-stock digest, auto-suggested RFQs, deadline reminders, overdue flags, client supply alerts and the weekly count reminder';

    public function handle(): int
    {
        $summary = [
            'low_stock_digest' => $this->lowStockDigest(),
            'rfqs_auto_created' => $this->autoSuggestRfqs(),
            'deadline_reminders' => $this->deadlineReminders(),
            'overdue_flags' => $this->overdueAlerts(),
            'client_supply_orders' => $this->clientSupplyAlert(),
            'client_returns_due' => $this->clientReturnAlert(),
            'weekly_count_due' => $this->weeklyCountReminder(),
        ];

        $this->info('VFRB daily digest complete: '.json_encode($summary));

        return self::SUCCESS;
    }

    // ── 1. Low-stock digest — one notification per manager per day ─────────
    private function lowStockDigest(): int
    {
        $lowStock = DB::table('materials')
            ->whereRaw(ClientMaterialService::lowStockSql())
            ->orderByRaw('(quantity_in_stock / NULLIF(reorder_threshold,0)) ASC')
            ->get();

        if ($lowStock->isEmpty()) {
            return 0;
        }

        $today = now()->toDateString();

        // Dedup: only one digest per day, even if the command runs twice
        // (e.g. once via cron, once via manual "Run Now").
        $alreadySent = DB::table('notifications')
            ->where('type', 'daily_digest')
            ->whereDate('date_sent', $today)
            ->exists();
        if ($alreadySent) {
            return 0;
        }

        $names = $lowStock->take(3)->pluck('material_name')->implode(', ');
        // FIX (recurring bug — see project notes): PHP string interpolation
        // via {$...} only allows property/method-access chains, not
        // arithmetic. `{$lowStock->count() - 3}` is a syntax error ("unexpected
        // token '-'"). Compute the value outside the string first.
        $extraCount = $lowStock->count() - 3;
        $more = $lowStock->count() > 3 ? " and {$extraCount} more" : '';
        $message = "{$lowStock->count()} material(s) are below reorder threshold: {$names}{$more}.";

        $managers = DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->whereIn('roles.name', ['manager', 'staff'])
            ->pluck('model_has_roles.model_id');

        $now = now();
        $rows = $managers->map(fn ($userId) => [
            'user_id' => $userId,
            'order_id' => null,
            'message' => $message,
            'type' => 'daily_digest',
            'title' => 'Daily Stock Digest',
            'is_read' => 0,
            'date_sent' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        if ($rows) {
            DB::table('notifications')->insert($rows);
        }

        return $lowStock->count();
    }

    // ── 2. Auto-suggest RFQs for materials below threshold ──────────────────
    private function autoSuggestRfqs(): int
    {
        $lowStock = DB::table('materials')
            ->whereRaw(ClientMaterialService::lowStockSql())
            ->get();

        if ($lowStock->isEmpty()) {
            return 0;
        }

        // A "system" creator is required — rfq_requests.created_by is used
        // in an INNER JOIN in rfqIndex(), so it can't be null. Attribute
        // auto-created RFQs to the first manager account; auto_generated=1
        // is what actually tells the UI (and staff) it wasn't a person.
        $systemUserId = DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('roles.name', 'manager')
            ->orderBy('model_has_roles.model_id')
            ->value('model_has_roles.model_id');

        if (! $systemUserId) {
            return 0; // no manager account exists yet — nothing to attribute to
        }

        $created = 0;
        $now = now();

        foreach ($lowStock as $material) {
            // Don't duplicate — skip if this material already has an open RFQ,
            // whether staff-created or auto-created previously.
            $hasOpenRfq = DB::table('rfq_requests')
                ->where('material_id', $material->material_id)
                ->where('status', 'open')
                ->exists();
            if ($hasOpenRfq) {
                continue;
            }

            // Threshold gap minus what open POs will still bring in; nothing left to buy means no RFQ.
            $incoming = (float) DB::table('purchase_order_items')->join('purchase_orders', 'purchase_orders.po_id', '=', 'purchase_order_items.po_id')
                ->where('purchase_order_items.material_id', $material->material_id)->whereIn('purchase_orders.status', ['draft', 'sent'])
                ->sum('purchase_order_items.qty');
            $qty = round($material->reorder_threshold - $material->quantity_in_stock - $incoming, 2);
            if ($qty <= 0) {
                continue;
            }

            DB::table('rfq_requests')->insert([
                'material_id' => $material->material_id,
                'qty_needed' => $qty,
                'needed_by_date' => $now->copy()->addDays(14)->toDateString(),
                'status' => 'open',
                'created_by' => $systemUserId,
                'auto_generated' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $created++;
        }

        return $created;
    }

    // ── 3. Deadline reminders — once per order, not daily nagging ──────────
    private function deadlineReminders(): int
    {
        $activeStages = [
            'pending', 'confirmed', 'pattern', 'segregation',
            'cutting', 'sewing', 'qc', 'pressing', 'packing', 'for_delivery',
        ];

        // The agreed date wins; the customer's preferred date is only a fallback.
        $nearDeadline = DB::table('orders')
            ->selectRaw('orders.*, COALESCE(promised_date, target_delivery_date) as due_on')
            ->whereIn('status', $activeStages)
            ->whereRaw('COALESCE(promised_date, target_delivery_date) BETWEEN ? AND ?', [now()->toDateString(), now()->addDays(7)->toDateString()])
            ->get();

        if ($nearDeadline->isEmpty()) {
            return 0;
        }

        $managers = DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->whereIn('roles.name', ['manager', 'staff'])
            ->pluck('model_has_roles.model_id');

        $notified = 0;
        $now = now();

        foreach ($nearDeadline as $order) {
            // Only once per order — check no reminder was ever sent for it.
            $alreadyNotified = DB::table('notifications')
                ->where('type', 'deadline_reminder')
                ->where('order_id', $order->order_id)
                ->exists();
            if ($alreadyNotified) {
                continue;
            }

            $daysLeft = (int) now()->startOfDay()
                ->diffInDays(Carbon::parse($order->due_on), false);
            $daysLeft = max(0, $daysLeft);
            $label = $daysLeft === 0 ? 'today' : "in {$daysLeft} day".($daysLeft === 1 ? '' : 's');

            $rows = [];

            // Notify the customer who placed the order
            $rows[] = [
                'user_id' => $order->user_id,
                'order_id' => $order->order_id,
                'message' => "Your order #{$order->order_id} ({$order->garment_type}) is due {$label}. Status: ".OrderStatusLabel::for($order->status).'.',
                'type' => 'deadline_reminder',
                'title' => 'Delivery Deadline Approaching',
                'is_read' => 0,
                'date_sent' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            // Notify staff/managers
            foreach ($managers as $userId) {
                $rows[] = [
                    'user_id' => $userId,
                    'order_id' => $order->order_id,
                    'message' => "Order #{$order->order_id} ({$order->garment_type}, {$order->quantity_ordered} pcs) is due {$label} and still in ".ucfirst($order->status).'.',
                    'type' => 'deadline_reminder',
                    'title' => 'Order Deadline Approaching',
                    'is_read' => 0,
                    'date_sent' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('notifications')->insert($rows);
            $notified++;
        }

        return $notified;
    }

    // ── 3b. Overdue: managers are flagged once per late order and once per unpaid invoice past its due date ──
    private function overdueAlerts(): int
    {
        $today = now()->toDateString();
        $managers = DB::table('model_has_roles')->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('roles.name', 'manager')->pluck('model_has_roles.model_id');
        $flagged = 0;

        $lateOrders = DB::table('orders')->whereNotIn('status', ['completed', 'cancelled'])
            ->whereRaw('COALESCE(promised_date, target_delivery_date) < ?', [$today])->get(['order_id', 'status', 'garment_type']);
        foreach ($lateOrders as $o) {
            $flagged += $this->flagOnce($managers, 'order_overdue', (int) $o->order_id, "Order #{$o->order_id} is overdue",
                "Order #{$o->order_id} ({$o->garment_type}) passed its agreed date and is still at ".str_replace('_', ' ', $o->status).'.');
        }

        $lateInvoices = DB::table('invoices')->where('status', 'issued')->whereNotNull('due_date')->where('due_date', '<', $today)
            ->get(['order_id', 'invoice_number', 'due_date']);
        foreach ($lateInvoices as $i) {
            $flagged += $this->flagOnce($managers, 'invoice_overdue', (int) $i->order_id, "{$i->invoice_number} is overdue",
                "{$i->invoice_number} for order #{$i->order_id} was due ".Carbon::parse($i->due_date)->toDateString().' and is still unpaid.');
        }

        return $flagged;
    }

    private function flagOnce($managers, string $type, int $orderId, string $title, string $message): int
    {
        if ($managers->isEmpty() || DB::table('notifications')->where('type', $type)->where('order_id', $orderId)->exists()) {
            return 0;
        }
        $now = now();
        DB::table('notifications')->insert($managers->map(fn ($uid) => [
            'user_id' => $uid, 'order_id' => $orderId, 'type' => $type, 'title' => $title, 'message' => $message,
            'is_read' => 0, 'date_sent' => $now, 'created_at' => $now, 'updated_at' => $now,
        ])->all());

        return 1;
    }

    // ── 4. Client supply — orders waiting on client-supplied materials ──────
    // Active orders before or at Cutting that are short (consumption known) or have received nothing yet.
    private function clientSupplyAlert(): int
    {
        $waiting = collect((new ClientMaterialService)->openOrders())
            ->filter(fn ($o) => in_array($o['status'], ['confirmed', 'pattern', 'segregation', 'cutting'], true)
                && ($o['short'] || $o['pending']));
        if ($waiting->isEmpty()) {
            return 0;
        }

        $parts = $waiting->take(4)->map(function ($o) {
            $who = $o['organization_name'] ?: $o['customer_name'];
            $what = $o['short'] ? ClientMaterialService::describe($o['short'], 'short').' short' : 'nothing delivered yet';

            return "#{$o['order_id']} {$who}: {$what}";
        })->implode('; ');
        $extra = $waiting->count() > 4 ? ' and '.($waiting->count() - 4).' more' : '';
        $message = "Client materials needed for {$waiting->count()} order(s): {$parts}{$extra}. Contact the client so production isn't delayed.";

        return $this->notifyStaffOnce('client_supply', 'Client Materials Missing', $message) ? $waiting->count() : 0;
    }

    // ── 4b. Client returns: cancelled orders still holding the client's material ──
    private function clientReturnAlert(): int
    {
        $held = collect((new ClientMaterialService)->openOrders())->where('status', 'cancelled');
        if ($held->isEmpty()) {
            return 0;
        }
        $parts = $held->take(4)->map(fn ($o) => "#{$o['order_id']} ".($o['organization_name'] ?: $o['customer_name']).': '
            .ClientMaterialService::describe(array_filter($o['lines'], fn ($l) => $l['on_hand'] > 0), 'on_hand'))->implode('; ');
        $extra = $held->count() > 4 ? ' and '.($held->count() - 4).' more' : '';
        $message = "Cancelled order(s) still hold client materials: {$parts}{$extra}. Return them to the client and record the return.";

        return $this->notifyStaffOnce('client_return', 'Client Materials to Return', $message) ? $held->count() : 0;
    }

    // ── 5. Weekly count — VFRB counts its own stock every Monday ────────────
    private function weeklyCountReminder(): int
    {
        $weekStart = PhysicalCountController::countWeekStart();
        $due = DB::table('materials')
            ->when(ClientMaterialService::ready(), fn ($q) => $q->where('ownership', 'vfrb'))
            ->whereNotExists(fn ($q) => $q->from('physical_count_logs')
                ->whereColumn('physical_count_logs.material_id', 'materials.material_id')
                ->where('physical_count_logs.count_date', '>=', $weekStart))
            ->count();
        if ($due === 0 || (! now()->isMonday() && now()->isWeekend())) {
            return 0;
        }

        $message = now()->isMonday()
            ? "Weekly stock count is today: {$due} VFRB-owned material(s) to count. Open Physical Count and print the count sheet."
            : "{$due} VFRB-owned material(s) are still not counted this week (count day was Monday).";

        return $this->notifyStaffOnce('weekly_count', now()->isMonday() ? 'Weekly Stock Count Today' : 'Weekly Count Not Finished', $message) ? $due : 0;
    }

    // One notification per manager/staff per day for this type; false if it was already sent today.
    private function notifyStaffOnce(string $type, string $title, string $message): bool
    {
        $now = now();
        if (DB::table('notifications')->where('type', $type)->whereDate('date_sent', $now->toDateString())->exists()) {
            return false;
        }
        $users = DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->whereIn('roles.name', ['manager', 'staff'])
            ->pluck('model_has_roles.model_id');
        $rows = $users->map(fn ($id) => [
            'user_id' => $id, 'order_id' => null, 'message' => $message, 'type' => $type, 'title' => $title,
            'is_read' => 0, 'date_sent' => $now, 'created_at' => $now, 'updated_at' => $now,
        ])->all();
        if ($rows) {
            DB::table('notifications')->insert($rows);
        }

        return true;
    }
}
