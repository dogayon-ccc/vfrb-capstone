<?php

// app/Http/Controllers/Api/DeliveryController.php
// VFRB Enterprise — Delivery Tracking
//
// SCHEMA VERIFIED against vfrb_db.sql:
//   delivery_tracking: tracking_id (PK), order_id, delivery_method (enum),
//     delivery_status (enum: preparing|dispatched|in_transit|delivered|returned),
//     courier_name, tracking_number, delivery_address, estimated_delivery_date,
//     actual_delivery_date, updated_by, notes
//
// CRITICAL RULE: column is delivery_status (NOT "status")
//
// delivery_method enum: pickup | vfrb_deliver | courier
// delivery_status enum: preparing | dispatched | in_transit | delivered | returned

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\BillingService;
use App\Services\ClientMaterialService;
use App\Services\ProductionStageService;
use App\Support\StudioSet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DeliveryController extends Controller
{
    // ── GET /api/admin/deliveries ─────────────────────────────────────────────
    public function index(Request $request)
    {
        $status = $request->input('delivery_status', '');
        $perPage = (int) $request->input('per_page', 20);

        $query = DB::table('delivery_tracking')
            ->join('orders', 'delivery_tracking.order_id', '=', 'orders.order_id')
            ->join('users', 'orders.user_id', '=', 'users.user_id')
            ->select(
                'delivery_tracking.*',
                'orders.garment_type',
                'orders.quantity_ordered',
                'orders.color',
                'users.name as customer_name',
                'users.contact_number as customer_contact',
                // FIX: DeliveryTracking.jsx falls back to this for the address
                // column when delivery_tracking.delivery_address is empty —
                // was never selected here (only in show()), so it silently
                // showed '—' even when the customer had a real address on file.
                'users.address as customer_address'
            );

        if ($status) {
            // delivery_status — never "status"
            $query->where('delivery_tracking.delivery_status', $status);
        }

        return response()->json(
            $query->orderByDesc('delivery_tracking.created_at')->paginate($perPage)
        );
    }

    // ── GET /api/admin/deliveries/{id} ────────────────────────────────────────
    public function show(int $id)
    {
        $row = DB::table('delivery_tracking')
            ->join('orders', 'delivery_tracking.order_id', '=', 'orders.order_id')
            ->join('users', 'orders.user_id', '=', 'users.user_id')
            ->where('delivery_tracking.tracking_id', $id)
            ->select(
                'delivery_tracking.*',
                'orders.garment_type', 'orders.quantity_ordered',
                'orders.color', 'orders.po_reference',
                'users.name as customer_name',
                'users.contact_number as customer_contact',
                'users.address as customer_address'
            )
            ->first();

        if (! $row) {
            return response()->json(['message' => 'Delivery record not found.'], 404);
        }

        return response()->json($row);
    }

    // ── POST /api/admin/deliveries ────────────────────────────────────────────
    // Create a delivery record for a completed/packing order
    public function store(Request $request)
    {
        $request->validate([
            'order_id' => 'required|integer|exists:orders,order_id',
            'delivery_method' => 'required|in:pickup,vfrb_deliver,courier',
            'delivery_address' => 'nullable|string|max:500',
            'courier_name' => 'nullable|string|max:100',
            'tracking_number' => 'nullable|string|max:100',
            'estimated_delivery_date' => 'nullable|date',
            'notes' => 'nullable|string|max:1000',
        ]);

        // Prevent duplicate delivery record per order
        $exists = DB::table('delivery_tracking')
            ->where('order_id', $request->input('order_id'))
            ->exists();
        if ($exists) {
            return response()->json(['message' => 'Delivery record already exists for this order.'], 409);
        }

        $id = DB::table('delivery_tracking')->insertGetId([
            'order_id' => $request->input('order_id'),
            'delivery_method' => $request->input('delivery_method'),
            'delivery_status' => 'preparing',   // always starts at preparing
            'courier_name' => $request->input('courier_name'),
            'tracking_number' => $request->input('tracking_number'),
            'delivery_address' => $request->input('delivery_address'),
            'estimated_delivery_date' => $request->input('estimated_delivery_date'),
            'updated_by' => Auth::id(),
            'notes' => $request->input('notes'),
            'created_at' => now(),
            'updated_at' => now(),
        ], 'tracking_id');

        return response()->json(
            DB::table('delivery_tracking')->where('tracking_id', $id)->first(),
            201
        );
    }

    // ── PATCH /api/admin/deliveries/{id}/status ───────────────────────────────
    // Prepared -> In transit -> Delivered (optionally partial: delivered_qty, capped at packed_total), or Returned.
    public function updateStatus(Request $request, int $id)
    {
        $request->validate([
            'delivery_status' => 'required|in:preparing,in_transit,delivered,returned',
            'delivered_qty' => 'nullable|integer|min:1',
            'courier_name' => 'nullable|string|max:100',
            'tracking_number' => 'nullable|string|max:100',
            'actual_delivery_date' => 'nullable|date',
            'delivery_address' => 'nullable|string|max:500',
            'notes' => 'nullable|string|max:1000',
        ]);

        $row = DB::table('delivery_tracking')->where('tracking_id', $id)->first();
        if (! $row) {
            return response()->json(['message' => 'Delivery record not found.'], 404);
        }

        $newStatus = $request->input('delivery_status');
        if ($request->filled('delivery_address')) {
            $row->delivery_address = trim($request->input('delivery_address'));
        }

        if (in_array($newStatus, ['preparing', 'in_transit'], true)) {
            return $this->moveUndelivered($request, $row, $newStatus);
        }
        if ($newStatus === 'returned') {
            return $this->recordReturn($request, $row);
        }

        if ($newStatus === 'delivered') {
            if ($row->delivery_status === 'delivered') {
                return response()->json(['message' => "Order #{$row->order_id} is already delivered."], 422);
            }
            $ordered = (int) DB::table('orders')->where('order_id', $row->order_id)->value('quantity_ordered');
            $packed = (int) DB::table('daily_output_logs')->where('order_id', $row->order_id)->where('stage', 'packing')->sum('total_output');
            $already = (int) ($row->delivered_qty ?? 0);
            $available = max(0, $packed - $already);
            $qty = (int) $request->input('delivered_qty', $available);
            if ($qty < 1 || $qty > $available) {
                return response()->json(['message' => "Only {$available} packed piece".($available === 1 ? '' : 's')." can be delivered now ({$already} of {$packed} packed already delivered).", 'remaining' => $available], 422);
            }

            // Partial delivery: record the count only; the order closes when everything ordered is delivered.
            if ($already + $qty < $ordered) {
                if ($blocker = $this->addressBlocker($row)) {
                    return response()->json(['message' => $blocker], 422);
                }
                $update = ['delivered_qty' => $already + $qty, 'updated_by' => Auth::id(), 'updated_at' => now()];
                if ($request->filled('delivery_address')) {
                    $update['delivery_address'] = $row->delivery_address;
                }
                if ($request->filled('notes')) {
                    $update['notes'] = $request->input('notes');
                }
                DB::table('delivery_tracking')->where('tracking_id', $id)->update($update);
                $customerId = DB::table('orders')->where('order_id', $row->order_id)->value('user_id');
                $this->notifyCustomer((int) $customerId, (int) $row->order_id, "{$qty} piece".($qty === 1 ? '' : 's')." of order #{$row->order_id} delivered ({$update['delivered_qty']} of {$ordered} so far).");
                Cache::forget('dashboard_stats');

                return response()->json([
                    'message' => "Partial delivery recorded: {$update['delivered_qty']} of {$ordered}.",
                    'delivery_status' => $row->delivery_status,
                    'delivered_qty' => $update['delivered_qty'],
                    'packed_total' => $packed,
                ]);
            }
        }

        // Delivered acts on the whole uniform set; every piece must pass the same checks before any is marked.
        $setRows = collect();
        if ($newStatus === 'delivered' && $row->delivery_status !== 'delivered') {
            $siblings = StudioSet::siblingIds((int) $row->order_id);
            $siblingRows = DB::table('delivery_tracking')->whereIn('order_id', $siblings)->get()->keyBy('order_id');
            $missing = array_filter($siblings, fn ($o) => ! isset($siblingRows[$o]));
            if ($missing) {
                return response()->json(['message' => 'Order #'.implode(', #', $missing).' in the same uniform set has not reached delivery yet. Deliver the set together.'], 422);
            }
            $setRows = $siblingRows->where('delivery_status', '!=', 'delivered')->values();
            foreach ([$row, ...$setRows] as $r) {
                if ($blocker = $this->deliveryBlocker($r)) {
                    return response()->json(['message' => $blocker], 422);
                }
            }
        }

        $update = [
            'delivery_status' => $newStatus,   // delivery_status — never "status"
            'updated_by' => Auth::id(),
            'updated_at' => now(),
        ];

        if ($request->filled('courier_name')) {
            $update['courier_name'] = $request->input('courier_name');
        }
        if ($request->filled('tracking_number')) {
            $update['tracking_number'] = $request->input('tracking_number');
        }
        if ($request->filled('actual_delivery_date')) {
            $update['actual_delivery_date'] = $request->input('actual_delivery_date');
        }
        if ($request->filled('notes')) {
            $update['notes'] = $request->input('notes');
        }
        if ($request->filled('delivery_address')) {
            $update['delivery_address'] = $row->delivery_address;
        }

        // Auto-set actual_delivery_date when delivered
        if ($newStatus === 'delivered' && empty($update['actual_delivery_date'])) {
            $update['actual_delivery_date'] = now()->toDateString();
        }

        DB::transaction(function () use ($id, $update, $newStatus, $row, $setRows) {
            DB::table('delivery_tracking')->where('tracking_id', $id)->update($update);
            if ($newStatus !== 'delivered') {
                return;
            }

            // Fully delivered: each row's delivered_qty becomes its order's quantity.
            $deliveredQty = DB::raw('(SELECT quantity_ordered FROM orders WHERE orders.order_id = delivery_tracking.order_id)');
            DB::table('delivery_tracking')->where('tracking_id', $id)->update(['delivered_qty' => $deliveredQty]);
            DB::table('delivery_tracking')->whereIn('tracking_id', $setRows->pluck('tracking_id'))->update([
                'delivery_status' => 'delivered',
                'delivered_qty' => $deliveredQty,
                'actual_delivery_date' => $update['actual_delivery_date'],
                'updated_by' => $update['updated_by'],
                'updated_at' => now(),
            ]);

            // Notify customer and close each delivered order (the set's other pieces included).
            foreach ([(int) $row->order_id, ...$setRows->pluck('order_id')->map(fn ($v) => (int) $v)] as $orderId) {
                $order = DB::table('orders')->where('order_id', $orderId)->select('user_id', 'garment_type', 'quantity_ordered')->first();
                if (! $order) {
                    continue;
                }
                $this->notifyCustomer($order->user_id, $orderId, "Your order of {$order->quantity_ordered} {$order->garment_type} has been delivered. Thank you!");
                DB::table('orders')->where('order_id', $orderId)->update(['status' => 'completed', 'updated_at' => now()]);
                AuditLog::record('order_status_changed', 'orders', $orderId);
            }
        });

        if ($newStatus === 'delivered') {
            foreach ([(int) $row->order_id, ...$setRows->pluck('order_id')->map(fn ($v) => (int) $v)] as $orderId) {
                $this->autoInvoice($orderId);
            }
        }

        Cache::forget('dashboard_stats');

        return response()->json([
            'message' => $setRows->isNotEmpty()
                ? 'Delivered, together with order #'.$setRows->pluck('order_id')->implode(', #').' (same uniform set).'
                : 'Delivery status updated.',
            'delivery_status' => $newStatus,
            'set_delivered_ids' => $setRows->pluck('order_id')->map(fn ($v) => (int) $v)->all(),
        ]);
    }

    // Best-effort: the delivery is already saved, so a billing problem only logs a warning.
    private function autoInvoice(int $orderId): void
    {
        try {
            $invoice = app(BillingService::class)->autoIssueOnDelivery($orderId, (int) Auth::id());
            if ($invoice) {
                $customerId = (int) DB::table('orders')->where('order_id', $orderId)->value('user_id');
                $this->notifyCustomer($customerId, $orderId, "{$invoice->invoice_number} for Order #{$orderId} is ready to view and download.");
            }
        } catch (\Throwable $e) {
            \Log::warning("Auto-invoice failed for order #{$orderId}: ".$e->getMessage());
        }
    }

    // Why this delivery can't be marked Delivered yet, or null: production must be finished and a drop-off address on file.
    private function deliveryBlocker(object $row): ?string
    {
        $open = (new ProductionStageService)->incompleteStages((int) $row->order_id, 'packing', true);
        if ($open) {
            return "Order #{$row->order_id} is still in production (".implode(', ', $open).'). Finish every stage before marking it delivered.';
        }
        // Closure: every client-supplied material is used, written off or returned.
        if ($client = (new ClientMaterialService)->closureBlocker((int) $row->order_id)) {
            return $client;
        }

        return $this->addressBlocker($row);
    }

    private function addressBlocker(object $row): ?string
    {
        $address = trim((string) $row->delivery_address);
        if ($row->delivery_method !== 'pickup' && ($address === '' || strcasecmp($address, 'Address pending') === 0)) {
            return "Order #{$row->order_id} has no delivery address. Add the address before marking it delivered.";
        }

        return null;
    }

    // ── PATCH /api/admin/deliveries/{id}/delivered ──────────────────────────────
    // DeliveryTracking.jsx quick-mark button
    public function markDelivered(Request $request, int $id)
    {
        $request->merge(['delivery_status' => 'delivered']);

        return $this->updateStatus($request, $id);
    }

    // In transit needs the same readiness as Delivered, so nothing ships before production and the address are done.
    private function moveUndelivered(Request $request, object $row, string $newStatus)
    {
        if ($row->delivery_status === 'delivered') {
            return response()->json(['message' => "Order #{$row->order_id} is already delivered."], 422);
        }
        if ($newStatus === 'in_transit' && ($blocker = $this->deliveryBlocker($row))) {
            return response()->json(['message' => $blocker], 422);
        }

        $update = ['delivery_status' => $newStatus, 'updated_by' => Auth::id(), 'updated_at' => now()]
            + array_filter($request->only('courier_name', 'tracking_number', 'notes'));
        if ($request->filled('delivery_address')) {
            $update['delivery_address'] = $row->delivery_address;
        }
        DB::table('delivery_tracking')->where('tracking_id', $row->tracking_id)->update($update);

        if ($newStatus === 'in_transit') {
            $customerId = (int) DB::table('orders')->where('order_id', $row->order_id)->value('user_id');
            $this->notifyCustomer($customerId, (int) $row->order_id, "Order #{$row->order_id} is on its way.");
        }
        Cache::forget('dashboard_stats');

        return response()->json(['message' => 'Delivery status updated.', 'delivery_status' => $newStatus]);
    }

    // A return reopens the order as packed-awaiting-delivery so it can be checked and delivered again; managers are flagged.
    private function recordReturn(Request $request, object $row)
    {
        if ($row->delivery_status !== 'delivered' && (int) $row->delivered_qty < 1) {
            return response()->json(['message' => "Nothing on order #{$row->order_id} has been delivered yet."], 422);
        }
        $reason = trim((string) $request->input('notes'));
        if ($reason === '') {
            return response()->json(['message' => 'Add the reason for the return.', 'errors' => ['notes' => ['Add the reason for the return.']]], 422);
        }

        $orderId = (int) $row->order_id;
        DB::transaction(function () use ($row, $reason, $orderId) {
            DB::table('delivery_tracking')->where('tracking_id', $row->tracking_id)->update([
                'delivery_status' => 'returned', 'delivered_qty' => 0, 'actual_delivery_date' => null,
                'notes' => "Returned: {$reason}", 'updated_by' => Auth::id(), 'updated_at' => now(),
            ]);
            if (DB::table('orders')->where('order_id', $orderId)->where('status', 'completed')->update(['status' => 'for_delivery', 'updated_at' => now()])) {
                AuditLog::record('order_status_changed', 'orders', $orderId);
            }

            $now = now();
            $managers = DB::table('model_has_roles')->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                ->where('roles.name', 'manager')->pluck('model_has_roles.model_id');
            foreach ($managers as $uid) {
                DB::table('notifications')->insert([
                    'user_id' => $uid, 'order_id' => $orderId, 'type' => 'warning', 'title' => "Order #{$orderId} returned",
                    'message' => "Order #{$orderId} was returned: {$reason}. It is reopened as awaiting delivery; check the goods and deliver again.",
                    'is_read' => 0, 'date_sent' => $now, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            $customerId = (int) DB::table('orders')->where('order_id', $orderId)->value('user_id');
            $this->notifyCustomer($customerId, $orderId, "VFRB recorded the return of order #{$orderId} and will contact you about the next delivery.");
        });
        Cache::forget('dashboard_stats');

        return response()->json(['message' => "Return recorded. Order #{$orderId} is reopened and managers were notified.", 'delivery_status' => 'returned']);
    }

    // Full payment completes a handover already under way: in transit, or a pickup waiting at VFRB. Blocked deliveries stay as they are.
    public function deliverIfPaid(int $orderId): void
    {
        $row = DB::table('delivery_tracking')->where('order_id', $orderId)->first();
        if (! $row || ! ($row->delivery_status === 'in_transit' || ($row->delivery_method === 'pickup' && $row->delivery_status === 'preparing'))) {
            return;
        }
        $remaining = (int) DB::table('orders')->where('order_id', $orderId)->value('quantity_ordered') - (int) $row->delivered_qty;
        $this->updateStatus(new Request(['delivery_status' => 'delivered', 'delivered_qty' => max(1, $remaining)]), (int) $row->tracking_id);
    }

    // ── GET /api/customer/deliveries/{orderId} ────────────────────────────────
    // Customer view of their own delivery
    public function customerShow(int $orderId)
    {
        $order = DB::table('orders')
            ->where('order_id', $orderId)
            ->where('user_id', Auth::id())
            ->first();

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        // Customer-safe fields only: staff notes, staff ids and the order's money fields stay inside VFRB.
        $delivery = DB::table('delivery_tracking')
            ->where('order_id', $orderId)
            ->first(['tracking_id', 'order_id', 'delivery_method', 'delivery_status', 'courier_name', 'tracking_number',
                'delivery_address', 'estimated_delivery_date', 'actual_delivery_date', 'delivered_qty']);

        return response()->json([
            'order' => OrderController::clientStatus((object) ['order_id' => $order->order_id, 'status' => $order->status]),
            'delivery' => $delivery,
        ]);
    }

    // ── Private: customer notification ───────────────────────────────────────
    private function notifyCustomer(int $userId, int $orderId, string $message): void
    {
        $now = now();
        DB::table('notifications')->insert([
            'user_id' => $userId,
            'order_id' => $orderId,
            'message' => $message,
            'type' => 'delivery',
            'is_read' => 0,
            'date_sent' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
