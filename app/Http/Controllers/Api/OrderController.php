<?php
// app/Http/Controllers/Api/OrderController.php
// VFRB Enterprise — Orders CRUD
//
// SCHEMA VERIFIED against vfrb_db.sql:
//   orders: order_id (PK), user_id, design_id, status (enum 11),
//     order_type (enum: direct|subcontract|bulk|rush),
//     payment_method, payment_terms, down_payment_amount,
//     quantity_ordered, sizing_type (enum: standard|custom),
//     target_delivery_date, negotiated_delivery_date,
//     discount_pct, notes, client_design_notes,
//     client_design_ref_file (path), ai_recommendation_status,
//     po_reference, color, garment_type, collar_type, sleeve_type,
//     pocket_type, studio_config (json), qc_required, qc_passed_at
//
//   measurements: measurement_id, user_id, order_id, type, size_label,
//     neck, chest, waist, hip, sleeve_length
//
//   material_recommendations: rec_id, order_id, material_name, category,
//     estimated_range, total_estimated_range, unit, ai_note,
//     display_order, status, customer_accepted, accepted_at
//
// DELETED FOREVER: material_formulas, order_size_breakdown — zero references here.
//
// OrderWizard.jsx sends multipart/form-data with:
//   garment_type, collar_type, sleeve_type, color, order_type,
//   quantity_ordered, deadline (→ target_delivery_date), po_reference,
//   special_notes, sizing_type, sizes (JSON string), measurements (JSON string),
//   custom_qty, client_design_notes, studio_config (JSON string), design_ref_file
//
// Response: { order: { order_id, ... } }  — OrderWizard navigates to /customer/orders/{id}

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    // ── GET /api/customer/orders ──────────────────────────────────────────────
    // Orders.jsx + Messages.jsx + AIMaterials.jsx: paginated order list
    // Supports ?status=active to filter in-production orders
    public function customerIndex(Request $request)
    {
        $userId  = Auth::id();
        $status  = $request->input('status', '');
        $perPage = (int) $request->input('per_page', 20);

        $query = DB::table('orders')
            ->where('user_id', $userId)
            ->orderByDesc('created_at');

        if ($status === 'active') {
            $query->whereNotIn('status', ['completed', 'cancelled']);
        } elseif ($status) {
            // Comma-separated statuses: ?status=pattern,cutting,sewing
            $statuses = array_map('trim', explode(',', $status));
            $query->whereIn('status', $statuses);
        }

        return response()->json($query->paginate($perPage));
    }

    // ── GET /api/customer/orders/{id} ─────────────────────────────────────────
    // OrderDetail.jsx: { order, recommendations, production_stages }
    public function customerShow(int $id)
    {
        $order = DB::table('orders')
            ->where('order_id', $id)
            ->where('user_id', Auth::id())
            ->first();

        if (!$order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        // Decode studio_config JSON for the frontend color zones
        if ($order->studio_config) {
            $order->studio_config = json_decode($order->studio_config, true);
        }

        // Attach AI recommendations
        $recommendations = DB::table('material_recommendations')
            ->where('order_id', $id)
            ->orderBy('display_order')
            ->get();

        // Attach production tracking per stage
        $tracking = DB::table('order_production_tracking')
            ->where('order_id', $id)
            ->get()
            ->keyBy('stage');

        return response()->json([
            'order'            => $order,
            'recommendations'  => $recommendations,
            'production_stages'=> $tracking,
        ]);
    }

    // ── POST /api/customer/orders ─────────────────────────────────────────────
    // OrderWizard.jsx sends multipart/form-data
    public function customerStore(Request $request)
    {
        $request->validate([
            'garment_type'     => 'required|string|max:60',
            'quantity_ordered' => 'required|integer|min:1',
            'color'            => 'nullable|string|max:50',
            'order_type'       => 'nullable|in:direct,subcontract,bulk,rush',
            'sizing_type'      => 'nullable|in:standard,custom',
            'collar_type'      => 'nullable|string|max:60',
            'sleeve_type'      => 'nullable|string|max:60',
            'pocket_type'      => 'nullable|string|max:60',
            'deadline'         => 'nullable|date',
            'po_reference'     => 'nullable|string|max:100',
            'client_design_notes' => 'nullable|string',
            'studio_config'    => 'nullable|string',    // JSON string from canvas
            'design_ref_file'  => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'sizes'            => 'nullable|string',    // JSON string
            'measurements'     => 'nullable|string',    // JSON string
            'custom_qty'       => 'nullable|integer|min:0',
        ]);

        // Handle design reference file upload
        $refFilePath = null;
        if ($request->hasFile('design_ref_file')) {
            $refFilePath = $request->file('design_ref_file')
                ->store('design-refs', 'public');
        }

        // Parse studio_config JSON string
        $studioConfig = null;
        if ($request->filled('studio_config')) {
            $decoded = json_decode($request->input('studio_config'), true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $studioConfig = $request->input('studio_config'); // store as JSON string
            }
        }

        $orderId = DB::table('orders')->insertGetId([
            'user_id'               => Auth::id(),
            'garment_type'          => $request->input('garment_type'),
            'collar_type'           => $request->input('collar_type'),
            'sleeve_type'           => $request->input('sleeve_type'),
            'pocket_type'           => $request->input('pocket_type'),
            'color'                 => $request->input('color'),
            'quantity_ordered'      => (int) $request->input('quantity_ordered'),
            'order_type'            => $request->input('order_type', 'direct'),
            'sizing_type'           => $request->input('sizing_type', 'standard'),
            'target_delivery_date'  => $request->input('deadline'),
            'po_reference'          => $request->input('po_reference'),
            'client_design_notes'   => $request->input('client_design_notes'),
            'client_design_ref_file'=> $refFilePath,
            'studio_config'         => $studioConfig,
            'status'                => 'pending',
            'ai_recommendation_status' => 'not_requested',
            'qc_required'           => 1,
            'notes'                 => $request->input('special_notes'),
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);

        // Store per-size quantities in measurements table if provided
        $sizes = json_decode($request->input('sizes', '{}'), true);
        if (is_array($sizes)) {
            foreach ($sizes as $sizeLabel => $qty) {
                if ($qty > 0) {
                    DB::table('measurements')->insert([
                        'order_id'    => $orderId,
                        'user_id'     => Auth::id(),
                        'type'        => 'standard',
                        'size_label'  => strtoupper($sizeLabel),
                        'qty'         => (int) $qty,
                        'created_at'  => now(),
                        'updated_at'  => now(),
                    ]);
                }
            }
        }

        // Store custom measurements if sizing_type = custom
        $customMeasurements = json_decode($request->input('measurements', '{}'), true);
        if (is_array($customMeasurements) && !empty($customMeasurements)) {
            DB::table('measurements')->insert([
                'order_id'      => $orderId,
                'user_id'       => Auth::id(),
                'type'          => 'custom',
                'size_label'    => 'custom',
                'chest'         => $customMeasurements['chest']        ?? null,
                'waist'         => $customMeasurements['waist']        ?? null,
                'hip'           => $customMeasurements['hip']          ?? null,
                'sleeve_length' => $customMeasurements['sleeve_length']?? null,
                'neck'          => $customMeasurements['neck']         ?? null,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }

        // Notify managers of new order
        $this->notifyManagers($orderId, "New order #$orderId submitted by customer.");

        Cache::forget('dashboard_stats');

        $order = DB::table('orders')->where('order_id', $orderId)->first();
        if ($order->studio_config) {
            $order->studio_config = json_decode($order->studio_config, true);
        }

        return response()->json(['order' => $order], 201);
    }

    // ── GET /api/customer/dashboard ───────────────────────────────────────────
    // CustomerDashboard.jsx KPI stats
    public function customerDashboard()
    {
        $userId = Auth::id();

        $totalOrders = DB::table('orders')->where('user_id', $userId)->count();
        $inProduction = DB::table('orders')
            ->where('user_id', $userId)
            ->whereNotIn('status', ['pending', 'completed', 'cancelled'])
            ->count();
        $completedOrders = DB::table('orders')
            ->where('user_id', $userId)
            ->where('status', 'completed')
            ->count();

        return response()->json([
            'total_orders'     => $totalOrders,
            'in_production'    => $inProduction,
            'completed_orders' => $completedOrders,
        ]);
    }

    // ── GET /api/admin/orders ─────────────────────────────────────────────────
    // Orders.jsx, QCChecklist.jsx, ProductionTracking.jsx, SalesTransactions.jsx
    // Supports ?status=qc, ?status=pattern,cutting,sewing, ?per_page=50
    public function adminIndex(Request $request)
    {
        $status  = $request->input('status', '');
        $perPage = min((int) $request->input('per_page', 20), 100);

        $query = DB::table('orders')
            ->join('users', 'orders.user_id', '=', 'users.user_id')
            ->select(
                'orders.*',
                'users.name as customer_name',
                'users.organization_name',
                'users.contact_number as customer_contact',
                'users.client_type'
            )
            ->orderByDesc('orders.created_at');

        if ($status) {
            if ($status === 'production') {
                $statuses = ['pattern','segregation','cutting','sewing','qc','pressing','packing'];
            } else {
                $statuses = array_map('trim', explode(',', $status));
            }
            $query->whereIn('orders.status', $statuses);
        }

        $result = $query->paginate($perPage);

        // Decode studio_config for each order
        $result->getCollection()->transform(function ($o) {
            if ($o->studio_config) {
                $o->studio_config = json_decode($o->studio_config, true);
            }
            return $o;
        });

        return response()->json($result);
    }

    // ── GET /api/admin/orders/{id} ────────────────────────────────────────────
    // Invoice.jsx (and other admin detail pages) expect order.user.*,
    // order.recommendations[] (with nested .material for stock lookups),
    // and order.transactions[] for the payment summary. None of these were
    // ever attached before — Invoice.jsx's BOM table and Payment Summary
    // silently rendered empty/zeroed no matter what was in the database.
    public function adminShow(int $id)
    {
        $order = $this->buildOrderDetail($id);
        if (!$order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $tracking = DB::table('order_production_tracking')
            ->where('order_id', $id)->get()->keyBy('stage');

        return response()->json([
            'order'    => $order,
            'tracking' => $tracking,
        ]);
    }

    // ── GET /api/admin/orders/{id}/invoice-pdf ────────────────────────────────
    // Real, downloadable invoice — barryvdh/laravel-dompdf was already in
    // composer.json but wired to ZERO routes before this. Reuses the exact
    // same data buildOrderDetail() feeds to the on-screen Invoice.jsx, so the
    // PDF and the screen can never silently disagree with each other.
    public function downloadInvoicePdf(int $id)
    {
        $order = $this->buildOrderDetail($id);
        if (!$order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        // Blade view uses array access ($order['field']) — flatten the
        // mixed stdClass/array structure buildOrderDetail() returns.
        $orderArray = json_decode(json_encode($order), true);

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.invoice', [
            'order'     => $orderArray,
            'printedAt' => now()->format('F d, Y g:i A'),
        ])->setPaper('a4', 'portrait');

        return $pdf->download("VFRB-Invoice-ORD-{$id}.pdf");
    }

    // ── Shared order-detail builder ────────────────────────────────────────────
    // Used by adminShow() (JSON) and downloadInvoicePdf() (PDF) — one query
    // path, so the two views of the same order can't drift apart.
    private function buildOrderDetail(int $id)
    {
        $order = DB::table('orders')
            ->join('users', 'orders.user_id', '=', 'users.user_id')
            ->where('orders.order_id', $id)
            ->select('orders.*', 'users.name as customer_name',
                     'users.organization_name',
                     'users.email as customer_email',
                     'users.contact_number as customer_contact')
            ->first();

        if (!$order) {
            return null;
        }

        if ($order->studio_config) {
            $order->studio_config = json_decode($order->studio_config, true);
        }

        // Nested `user` object — Invoice.jsx / invoice.blade.php read
        // order.user.{name, organization_name, email, contact_number}.
        $order->user = [
            'name'              => $order->customer_name,
            'organization_name' => $order->organization_name,
            'email'             => $order->customer_email,
            'contact_number'    => $order->customer_contact,
        ];

        // BOM — material_recommendations left-joined to materials so the
        // "In Stock" column has real numbers instead of always 0.
        $order->recommendations = DB::table('material_recommendations')
            ->leftJoin('materials', 'material_recommendations.material_id', '=', 'materials.material_id')
            ->where('material_recommendations.order_id', $id)
            ->orderBy('material_recommendations.display_order')
            ->select(
                'material_recommendations.*',
                'materials.material_name as linked_material_name',
                'materials.unit as material_unit',
                'materials.quantity_in_stock as material_quantity_in_stock'
            )
            ->get()
            ->map(function ($rec) {
                $rec->material = [
                    'material_name'     => $rec->linked_material_name,
                    'unit'              => $rec->material_unit,
                    'quantity_in_stock' => $rec->material_quantity_in_stock,
                ];
                return $rec;
            });

        // Payment history — Invoice.jsx / invoice.blade.php read
        // order.transactions[0] for amount_paid / amount_total / balance_due.
        $order->transactions = DB::table('sales_transactions')
            ->where('order_id', $id)
            ->orderByDesc('date_processed')
            ->get();

        return $order;
    }

    // ── PATCH /api/admin/orders/{id} ─────────────────────────────────────────
    // Admin updates order status (confirm, cancel)
    public function adminUpdate(Request $request, int $id)
    {
        $order = DB::table('orders')->where('order_id', $id)->first();
        if (!$order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $request->validate([
            'status' => 'sometimes|in:pending,confirmed,pattern,segregation,cutting,sewing,qc,pressing,packing,completed,cancelled',
            'negotiated_delivery_date' => 'nullable|date',
            'notes' => 'nullable|string',
            // agreed_total (Aug 23 2026 — order confirm/cancel UI): lets a
            // manager set the negotiated total at confirmation time, before
            // production starts. Safe alongside SalesTransactionController,
            // which only ever sets this field `if ($order->agreed_total ===
            // null)` on first payment — pre-setting it here just means that
            // first-payment logic uses the manager's number instead of
            // deriving a fresh one. Never overwrites an already-set total
            // (see the extra guard below) so a later payment can't
            // accidentally clobber a confirmed negotiation.
            'agreed_total' => 'nullable|numeric|min:0',
        ]);

        $fields = collect($request->only(['status', 'negotiated_delivery_date', 'notes']))
            ->filter(fn($v) => !is_null($v))
            ->toArray();

        // Only ever set agreed_total, never overwrite an existing value
        // through this endpoint — once a real total is locked in (whether
        // by a manager here or by the first payment), it stays read-only,
        // matching the schema comment on orders.agreed_total.
        if ($request->filled('agreed_total') && $order->agreed_total === null) {
            $fields['agreed_total'] = $request->input('agreed_total');
        }

        $fields['updated_at'] = now();
        DB::table('orders')->where('order_id', $id)->update($fields);

        if (isset($fields['status'])) {
            $statusMessage = $fields['status'] === 'cancelled' && $request->filled('notes')
                ? "Your order #{$id} was cancelled. Reason: {$request->input('notes')}"
                : "Your order #{$id} status has been updated to " . ucfirst($fields['status']) . ".";
            $this->notifyCustomer($order->user_id, $id, $statusMessage);
        }

        Cache::forget('dashboard_stats');

        return response()->json(DB::table('orders')->where('order_id', $id)->first());
    }

    // ── Private helpers ───────────────────────────────────────────────────────
    private function notifyManagers(int $orderId, string $message): void
    {
        $now = now();
        $managers = DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('roles.name', 'manager')
            ->pluck('model_has_roles.model_id');

        foreach ($managers as $uid) {
            DB::table('notifications')->insert([
                'user_id'    => $uid,
                'order_id'   => $orderId,
                'message'    => $message,
                'type'       => 'order',
                'is_read'    => 0,
                'date_sent'  => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function notifyCustomer(int $userId, int $orderId, string $message): void
    {
        $now = now();
        DB::table('notifications')->insert([
            'user_id'    => $userId,
            'order_id'   => $orderId,
            'message'    => $message,
            'type'       => 'order',
            'is_read'    => 0,
            'date_sent'  => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    // ── Distinct garment types actually in use ──────────────────────────────
    // GET /api/admin/orders/garment-types
    // FIX (Aug 1 2026 audit): garment_type is a free-text varchar, not an
    // enum — old seeded orders use 'polo_shirt' snake_case, current
    // OrderWizard.jsx submits 'Polo Shirt' Title Case. A staff member typing
    // a rate's garment_type by hand could easily set a value that matches
    // neither. This returns the real distinct values currently in the
    // orders table so the rate-management UI can offer a dropdown of values
    // that actually exist, instead of free text.
    public function garmentTypes()
    {
        $types = DB::table('orders')
            ->whereNotNull('garment_type')
            ->distinct()
            ->orderBy('garment_type')
            ->pluck('garment_type');

        return response()->json(['garment_types' => $types]);
    }
}