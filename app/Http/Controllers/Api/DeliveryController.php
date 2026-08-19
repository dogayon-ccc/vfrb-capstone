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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class DeliveryController extends Controller
{
    // ── GET /api/admin/deliveries ─────────────────────────────────────────────
    public function index(Request $request)
    {
        $status = $request->input('delivery_status', '');
        $perPage = (int) $request->input('per_page', 20);

        $query = DB::table('delivery_tracking')
            ->join('orders', 'delivery_tracking.order_id', '=', 'orders.order_id')
            ->join('users',  'orders.user_id', '=', 'users.user_id')
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
            ->join('users',  'orders.user_id', '=', 'users.user_id')
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

        if (!$row) {
            return response()->json(['message' => 'Delivery record not found.'], 404);
        }

        return response()->json($row);
    }

    // ── POST /api/admin/deliveries ────────────────────────────────────────────
    // Create a delivery record for a completed/packing order
    public function store(Request $request)
    {
        $request->validate([
            'order_id'                 => 'required|integer|exists:orders,order_id',
            'delivery_method'          => 'required|in:pickup,vfrb_deliver,courier',
            'delivery_address'         => 'nullable|string|max:500',
            'courier_name'             => 'nullable|string|max:100',
            'tracking_number'          => 'nullable|string|max:100',
            'estimated_delivery_date'  => 'nullable|date',
            'notes'                    => 'nullable|string|max:1000',
        ]);

        // Prevent duplicate delivery record per order
        $exists = DB::table('delivery_tracking')
            ->where('order_id', $request->input('order_id'))
            ->exists();
        if ($exists) {
            return response()->json(['message' => 'Delivery record already exists for this order.'], 409);
        }

        $id = DB::table('delivery_tracking')->insertGetId([
            'order_id'                => $request->input('order_id'),
            'delivery_method'         => $request->input('delivery_method'),
            'delivery_status'         => 'preparing',   // always starts at preparing
            'courier_name'            => $request->input('courier_name'),
            'tracking_number'         => $request->input('tracking_number'),
            'delivery_address'        => $request->input('delivery_address'),
            'estimated_delivery_date' => $request->input('estimated_delivery_date'),
            'updated_by'              => Auth::id(),
            'notes'                   => $request->input('notes'),
            'created_at'              => now(),
            'updated_at'              => now(),
        ]);

        return response()->json(
            DB::table('delivery_tracking')->where('tracking_id', $id)->first(),
            201
        );
    }

    // ── PATCH /api/admin/deliveries/{id}/status ───────────────────────────────
    // Staff updates delivery_status (NOT "status")
    public function updateStatus(Request $request, int $id)
    {
        $request->validate([
            'delivery_status'      => 'required|in:preparing,dispatched,in_transit,delivered,returned',
            'courier_name'         => 'nullable|string|max:100',
            'tracking_number'      => 'nullable|string|max:100',
            'actual_delivery_date' => 'nullable|date',
            'notes'                => 'nullable|string|max:1000',
        ]);

        $row = DB::table('delivery_tracking')->where('tracking_id', $id)->first();
        if (!$row) {
            return response()->json(['message' => 'Delivery record not found.'], 404);
        }

        $newStatus = $request->input('delivery_status');

        $update = [
            'delivery_status' => $newStatus,   // delivery_status — never "status"
            'updated_by'      => Auth::id(),
            'updated_at'      => now(),
        ];

        if ($request->filled('courier_name'))         $update['courier_name']         = $request->input('courier_name');
        if ($request->filled('tracking_number'))      $update['tracking_number']      = $request->input('tracking_number');
        if ($request->filled('actual_delivery_date')) $update['actual_delivery_date'] = $request->input('actual_delivery_date');
        if ($request->filled('notes'))                $update['notes']                = $request->input('notes');

        // Auto-set actual_delivery_date when delivered
        if ($newStatus === 'delivered' && empty($update['actual_delivery_date'])) {
            $update['actual_delivery_date'] = now()->toDateString();
        }

        DB::table('delivery_tracking')->where('tracking_id', $id)->update($update);

        // Notify customer when delivered
        if ($newStatus === 'delivered') {
            $order = DB::table('orders')
                ->where('order_id', $row->order_id)
                ->select('user_id', 'garment_type', 'quantity_ordered')
                ->first();

            if ($order) {
                $this->notifyCustomer(
                    $order->user_id,
                    $row->order_id,
                    "Your order of {$order->quantity_ordered} {$order->garment_type} has been delivered. Thank you!"
                );

                // Update order status to completed
                DB::table('orders')
                    ->where('order_id', $row->order_id)
                    ->update(['status' => 'completed', 'updated_at' => now()]);
            }
        }

        Cache::forget('dashboard_stats');

        return response()->json([
            'message'         => 'Delivery status updated.',
            'delivery_status' => $newStatus,
        ]);
    }

    // ── PATCH /api/admin/deliveries/{id}/delivered ──────────────────────────────
    // DeliveryTracking.jsx quick-mark button
    public function markDelivered(Request $request, int $id)
    {
        $request->merge(['delivery_status' => 'delivered']);
        return $this->updateStatus($request, $id);
    }

    // ── GET /api/customer/deliveries/{orderId} ────────────────────────────────
    // Customer view of their own delivery
    public function customerShow(int $orderId)
    {
        $order = DB::table('orders')
            ->where('order_id', $orderId)
            ->where('user_id', Auth::id())
            ->first();

        if (!$order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $delivery = DB::table('delivery_tracking')
            ->where('order_id', $orderId)
            ->first();

        return response()->json([
            'order'    => $order,
            'delivery' => $delivery,
        ]);
    }

    // ── Private: customer notification ───────────────────────────────────────
    private function notifyCustomer(int $userId, int $orderId, string $message): void
    {
        $now = now();
        DB::table('notifications')->insert([
            'user_id'    => $userId,
            'order_id'   => $orderId,
            'message'    => $message,
            'type'       => 'delivery',
            'is_read'    => 0,
            'date_sent'  => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
