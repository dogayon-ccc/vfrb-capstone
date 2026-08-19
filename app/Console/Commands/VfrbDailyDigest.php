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
//
// Run manually:   php artisan vfrb:daily-digest
// Run on schedule: registered in routes/console.php
// Also callable from the API via POST /api/admin/automation/run-digest
// (AutomationController@run) for an on-demand "Run Now" button — this
// command is the single source of truth either way, so manual and
// scheduled runs can never drift out of sync.

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class VfrbDailyDigest extends Command
{
    protected $signature   = 'vfrb:daily-digest';
    protected $description = 'Low-stock digest, auto-suggested RFQs, and delivery deadline reminders';

    public function handle(): int
    {
        $summary = [
            'low_stock_digest'   => $this->lowStockDigest(),
            'rfqs_auto_created'  => $this->autoSuggestRfqs(),
            'deadline_reminders' => $this->deadlineReminders(),
        ];

        $this->info('VFRB daily digest complete: ' . json_encode($summary));
        return self::SUCCESS;
    }

    // ── 1. Low-stock digest — one notification per manager per day ─────────
    private function lowStockDigest(): int
    {
        $lowStock = DB::table('materials')
            ->whereRaw('quantity_in_stock <= reorder_threshold')
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

        $names   = $lowStock->take(3)->pluck('material_name')->implode(', ');
        // FIX (recurring bug — see project notes): PHP string interpolation
        // via {$...} only allows property/method-access chains, not
        // arithmetic. `{$lowStock->count() - 3}` is a syntax error ("unexpected
        // token '-'"). Compute the value outside the string first.
        $extraCount = $lowStock->count() - 3;
        $more    = $lowStock->count() > 3 ? " and {$extraCount} more" : '';
        $message = "{$lowStock->count()} material(s) are below reorder threshold: {$names}{$more}.";

        $managers = DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->whereIn('roles.name', ['manager', 'staff'])
            ->pluck('model_has_roles.model_id');

        $now = now();
        $rows = $managers->map(fn($userId) => [
            'user_id'    => $userId,
            'order_id'   => null,
            'message'    => $message,
            'type'       => 'daily_digest',
            'title'      => 'Daily Stock Digest',
            'is_read'    => 0,
            'date_sent'  => $now,
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
            ->whereRaw('quantity_in_stock <= reorder_threshold')
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

        if (!$systemUserId) {
            return 0; // no manager account exists yet — nothing to attribute to
        }

        $created = 0;
        $now     = now();

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

            $deficit = max(0, $material->reorder_threshold - $material->quantity_in_stock);
            $qty     = round(($material->reorder_threshold * 2) - $material->quantity_in_stock, 2);
            $qty     = max($qty, $deficit, $material->reorder_threshold); // sane floor

            DB::table('rfq_requests')->insert([
                'material_id'     => $material->material_id,
                'qty_needed'      => $qty,
                'needed_by_date'  => $now->copy()->addDays(14)->toDateString(),
                'status'          => 'open',
                'created_by'      => $systemUserId,
                'auto_generated'  => 1,
                'created_at'      => $now,
                'updated_at'      => $now,
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
            'cutting', 'sewing', 'qc', 'pressing', 'packing',
        ];

        $nearDeadline = DB::table('orders')
            ->whereIn('status', $activeStages)
            ->whereNotNull('target_delivery_date')
            ->where('target_delivery_date', '<=', now()->addDays(7)->toDateString())
            ->where('target_delivery_date', '>=', now()->toDateString())
            ->get();

        if ($nearDeadline->isEmpty()) {
            return 0;
        }

        $managers = DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->whereIn('roles.name', ['manager', 'staff'])
            ->pluck('model_has_roles.model_id');

        $notified = 0;
        $now      = now();

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
                ->diffInDays(\Carbon\Carbon::parse($order->target_delivery_date), false);
            $daysLeft = max(0, $daysLeft);
            $label    = $daysLeft === 0 ? 'today' : "in {$daysLeft} day" . ($daysLeft === 1 ? '' : 's');

            $rows = [];

            // Notify the customer who placed the order
            $rows[] = [
                'user_id'    => $order->user_id,
                'order_id'   => $order->order_id,
                'message'    => "Your order #{$order->order_id} ({$order->garment_type}) is due {$label}. Current stage: " . ucfirst($order->status) . '.',
                'type'       => 'deadline_reminder',
                'title'      => 'Delivery Deadline Approaching',
                'is_read'    => 0,
                'date_sent'  => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            // Notify staff/managers
            foreach ($managers as $userId) {
                $rows[] = [
                    'user_id'    => $userId,
                    'order_id'   => $order->order_id,
                    'message'    => "Order #{$order->order_id} ({$order->garment_type}, {$order->quantity_ordered} pcs) is due {$label} and still in " . ucfirst($order->status) . '.',
                    'type'       => 'deadline_reminder',
                    'title'      => 'Order Deadline Approaching',
                    'is_read'    => 0,
                    'date_sent'  => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('notifications')->insert($rows);
            $notified++;
        }

        return $notified;
    }
}
