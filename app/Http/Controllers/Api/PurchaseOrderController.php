<?php
// app/Http/Controllers/Api/PurchaseOrderController.php
// VFRB Enterprise — Purchase Orders + RFQ Flow
//
// SCHEMA VERIFIED against vfrb_db.sql:
//   purchase_orders: po_id (PK), po_number, supplier_id, created_by,
//     order_id (nullable), status (enum: draft|sent|received|closed|cancelled),
//     items (longtext JSON), total_amount, expected_delivery_date,
//     actual_delivery_date, notes,
//     order_color_hex, received_color_hex, color_mismatch (tinyint),
//     color_confirmed (tinyint), color_notes
//
//   rfq_requests: rfq_id (PK), material_id, qty_needed (decimal),
//     needed_by_date (date), status (enum: open|closed),
//     created_by
//
//   rfq_responses: response_id (PK), rfq_id, supplier_id, unit_price,
//     qty_available (decimal), lead_time_days (smallint),
//     notes (text), responded_at
//
//   suppliers: supplier_id (PK), supplier_name, contact_person, email,
//     phone, supplier_type, lead_time_days
//
//   materials: material_id, material_name, unit, quantity_in_stock,
//              reorder_threshold, unit_cost   ← unit_cost not unit_price
//   inventory_logs: log_id, material_id, recorded_by, type, change_qty, reason, log_date
//
// RFQ FLOW:
//   staff: POST /api/admin/rfq → creates rfq_request (open)
//   staff: POST /api/admin/rfq/{id}/respond → logs supplier response (rfq_responses)
//   manager: POST /api/admin/rfq/{id}/convert-po → creates purchase_order, closes rfq
//   staff: PATCH /api/admin/purchase-orders/{id}/receive → receives goods, updates stock
//   staff: PATCH /api/admin/purchase-orders/{id}/confirm-color → confirms color match
//
// PO COLOR SWATCH:
//   VFRB interview: when fabric arrives, staff compares to order swatch.
//   receive() sets received_color_hex, computes RGB ΔE, flags color_mismatch.
//   confirmColor() = manager override to unblock cutting despite mismatch.

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PurchaseOrderController extends Controller
{
    // ──────────────────────────────────────────────────────────────────────────
    // PURCHASE ORDERS
    // ──────────────────────────────────────────────────────────────────────────

    // ── GET /api/admin/purchase-orders ───────────────────────────────────────
    public function index(Request $request)
    {
        $status  = $request->input('status', '');
        $perPage = (int) $request->input('per_page', 20);

        $query = DB::table('purchase_orders')
            ->join('suppliers', 'purchase_orders.supplier_id', '=', 'suppliers.supplier_id')
            ->join('users',     'purchase_orders.created_by',  '=', 'users.user_id')
            ->select(
                'purchase_orders.*',
                'suppliers.supplier_name',
                'suppliers.contact_person',
                'suppliers.phone',
                'users.name as created_by_name'
            );

        if ($status) {
            $query->where('purchase_orders.status', $status);
        }

        $pos = $query->orderByDesc('purchase_orders.created_at')->paginate($perPage);

        $itemsByPo = $this->itemsForPoIds($pos->pluck('po_id')->all());
        $pos->getCollection()->transform(function ($po) use ($itemsByPo) {
            $po->items = $itemsByPo[$po->po_id] ?? [];
            return $po;
        });

        return response()->json($pos);
    }

    // Bulk-fetches purchase_order_items for a set of POs in one query,
    // grouped by po_id — avoids an N+1 when attaching items to a list.
    private function itemsForPoIds(array $poIds): array
    {
        if (!$poIds) return [];

        return DB::table('purchase_order_items')
            ->join('materials', 'purchase_order_items.material_id', '=', 'materials.material_id')
            ->whereIn('purchase_order_items.po_id', $poIds)
            ->select('purchase_order_items.*', 'materials.material_name', 'materials.unit')
            ->get()
            ->groupBy('po_id')
            ->map(fn($rows) => $rows->values())
            ->all();
    }

    // ── GET /api/admin/purchase-orders/{id} ──────────────────────────────────
    public function show(int $id)
    {
        $po = DB::table('purchase_orders')
            ->join('suppliers', 'purchase_orders.supplier_id', '=', 'suppliers.supplier_id')
            ->join('users',     'purchase_orders.created_by',  '=', 'users.user_id')
            ->where('purchase_orders.po_id', $id)
            ->select('purchase_orders.*', 'suppliers.supplier_name',
                     'suppliers.contact_person', 'suppliers.phone', 'suppliers.email',
                     'users.name as created_by_name')
            ->first();

        if (!$po) {
            return response()->json(['message' => 'Purchase order not found.'], 404);
        }

        $po->items = $this->itemsForPoIds([$po->po_id])[$po->po_id] ?? [];

        return response()->json($po);
    }

    // ── POST /api/admin/purchase-orders ──────────────────────────────────────
    public function store(Request $request)
    {
        $request->validate([
            'supplier_id'             => 'required|integer|exists:suppliers,supplier_id',
            'items'                   => 'required|array|min:1',
            'items.*.material_id'     => 'required|integer|exists:materials,material_id',
            'items.*.qty'             => 'required|numeric|min:0.01',
            'items.*.unit_cost'       => 'required|numeric|min:0',
            'expected_delivery_date'  => 'nullable|date',
            'order_id'                => 'nullable|integer|exists:orders,order_id',
            'order_color_hex'         => 'nullable|regex:/^#[0-9A-Fa-f]{6}$/', // real hex, not just 7 chars
            'notes'                   => 'nullable|string|max:1000',
        ]);

        $total = collect($request->input('items'))
            ->sum(fn($i) => $i['qty'] * $i['unit_cost']);

        $poNumber = 'PO-' . now()->format('Ymd') . '-' . strtoupper(substr(uniqid(), -5));

        $id = DB::table('purchase_orders')->insertGetId([
            'po_number'               => $poNumber,
            'supplier_id'             => $request->input('supplier_id'),
            'created_by'              => Auth::id(),
            'order_id'                => $request->input('order_id'),
            'status'                  => 'draft',
            'total_amount'            => $total,
            'expected_delivery_date'  => $request->input('expected_delivery_date'),
            'order_color_hex'         => $request->input('order_color_hex'),
            'color_mismatch'          => 0,
            'color_confirmed'         => 0,
            'notes'                   => $request->input('notes'),
            'created_at'              => now(),
            'updated_at'              => now(),
        ]);

        $now = now();
        DB::table('purchase_order_items')->insert(
            collect($request->input('items'))->map(fn($i) => [
                'po_id'       => $id,
                'material_id' => $i['material_id'],
                'qty'         => $i['qty'],
                'unit_cost'   => $i['unit_cost'],
                'created_at'  => $now,
                'updated_at'  => $now,
            ])->all()
        );

        Cache::forget('dashboard_stats');

        $po = DB::table('purchase_orders')->where('po_id', $id)->first();
        $po->items = $this->itemsForPoIds([$id])[$id] ?? [];

        return response()->json($po, 201);
    }

    // ── PATCH /api/admin/purchase-orders/{id}/receive ────────────────────────
    // Staff logs goods receipt — updates stock + color swatch check
    public function receive(Request $request, int $id)
    {
        $po = DB::table('purchase_orders')->where('po_id', $id)->first();
        if (!$po) {
            return response()->json(['message' => 'Purchase order not found.'], 404);
        }

        if (!in_array($po->status, ['draft', 'sent'])) {
            return response()->json(['message' => "Cannot receive a PO with status '{$po->status}'."], 422);
        }

        $request->validate([
            'received_color_hex' => 'nullable|regex:/^#[0-9A-Fa-f]{6}$/',
            'notes'              => 'nullable|string|max:500',
        ]);

        $receivedHex = $request->input('received_color_hex');
        $mismatch    = 0;

        // Compute ΔE if both hex values are present
        if ($receivedHex && $po->order_color_hex) {
            $deltaE   = $this->computeDeltaE($po->order_color_hex, $receivedHex);
            $mismatch = $deltaE > 5 ? 1 : 0;
        }

        $update = [
            'status'             => 'received',
            'actual_delivery_date'=> now()->toDateString(),
            'received_color_hex' => $receivedHex,
            'color_mismatch'     => $mismatch,
            'color_confirmed'    => $mismatch ? 0 : 1, // auto-confirm if no mismatch
            'notes'              => $request->input('notes', $po->notes),
            'updated_at'         => now(),
        ];

        // FIX: status update + N stock increments + N inventory_logs inserts
        // were previously separate, unguarded writes. If any item failed
        // partway (e.g. a corrupted items JSON blob, or a material deleted
        // after the PO was created), the PO would already be marked
        // 'received' with only some materials actually credited — silent,
        // hard-to-detect stock drift. Wrapped in a transaction so it's all
        // or nothing, matching the pattern already used in OutputLogController.
        DB::beginTransaction();
        try {
            DB::table('purchase_orders')->where('po_id', $id)->update($update);

            // Update stock for each item in the PO
            $items = DB::table('purchase_order_items')->where('po_id', $id)->get();
            foreach ($items as $item) {
                DB::table('materials')
                    ->where('material_id', $item->material_id)
                    ->increment('quantity_in_stock', $item->qty);

                DB::table('inventory_logs')->insert([
                    'material_id' => $item->material_id,
                    'recorded_by' => Auth::id(),
                    'type'        => 'stock_in',
                    'change_qty'  => $item->qty,
                    'reason'      => "Goods receipt — PO {$po->po_number}",
                    'log_date'    => now(),
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to record goods receipt. Please try again.'], 500);
        }

        // Notify managers if color mismatch
        if ($mismatch) {
            $this->notifyManagers(
                null,
                "Color mismatch on PO {$po->po_number}. Received: {$receivedHex}, "
                . "Ordered: {$po->order_color_hex}. Cutting is held pending confirmation."
            );
        }

        Cache::forget('materials_list');
        Cache::forget('dashboard_stats');
        Cache::forget('mrp_alerts');

        return response()->json([
            'message'        => $mismatch
                ? 'Goods received with COLOR MISMATCH. Cutting is held pending manager confirmation.'
                : 'Goods received successfully. Stock updated.',
            'color_mismatch' => (bool) $mismatch,
            'po_status'      => 'received',
        ]);
    }

    // ── PATCH /api/admin/purchase-orders/{id}/confirm-color ──────────────────
    // Manager override — accepts received color despite mismatch
    public function confirmColor(Request $request, int $id)
    {
        $po = DB::table('purchase_orders')->where('po_id', $id)->first();
        if (!$po) {
            return response()->json(['message' => 'Purchase order not found.'], 404);
        }

        $request->validate([
            'color_notes' => 'required|string|max:500',
        ]);

        DB::table('purchase_orders')
            ->where('po_id', $id)
            ->update([
                'color_confirmed' => 1,
                'color_notes'     => $request->input('color_notes'),
                'updated_at'      => now(),
            ]);

        Cache::forget('dashboard_stats');
        Cache::forget('mrp_alerts');

        return response()->json(['message' => 'Color confirmed. Cutting can proceed.']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // RFQ FLOW
    // ──────────────────────────────────────────────────────────────────────────

    // ── GET /api/admin/rfq ────────────────────────────────────────────────────
    public function rfqIndex(Request $request)
    {
        $perPage = (int) $request->input('per_page', 20);
        $status  = $request->input('status', '');

        $query = DB::table('rfq_requests')
            ->join('materials', 'rfq_requests.material_id', '=', 'materials.material_id')
            ->join('users',     'rfq_requests.created_by',  '=', 'users.user_id')
            ->select(
                'rfq_requests.*',
                'materials.material_name', 'materials.unit', 'materials.unit_cost',
                'users.name as created_by_name'
            );

        if ($status) {
            $query->where('rfq_requests.status', $status);
        }

        $rfqs = $query->orderByDesc('rfq_requests.created_at')->paginate($perPage);

        // Attach responses to each RFQ
        $rfqs->getCollection()->transform(function ($rfq) {
            $rfq->responses = DB::table('rfq_responses')
                ->join('suppliers', 'rfq_responses.supplier_id', '=', 'suppliers.supplier_id')
                ->where('rfq_responses.rfq_id', $rfq->rfq_id)
                ->select('rfq_responses.*', 'suppliers.supplier_name', 'suppliers.contact_person')
                ->get();
            return $rfq;
        });

        return response()->json($rfqs);
    }

    // ── POST /api/admin/rfq ───────────────────────────────────────────────────
    // Staff creates new RFQ
    public function rfqStore(Request $request)
    {
        $request->validate([
            'material_id'    => 'required|integer|exists:materials,material_id',
            'qty_needed'     => 'required|numeric|min:0.01',
            'needed_by_date' => 'nullable|date',
        ]);

        $id = DB::table('rfq_requests')->insertGetId([
            'material_id'    => $request->input('material_id'),
            'qty_needed'     => $request->input('qty_needed'),
            'needed_by_date' => $request->input('needed_by_date'),
            'status'         => 'open',
            'created_by'     => Auth::id(),
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $rfq = DB::table('rfq_requests')
            ->join('materials', 'rfq_requests.material_id', '=', 'materials.material_id')
            ->where('rfq_requests.rfq_id', $id)
            ->select('rfq_requests.*', 'materials.material_name', 'materials.unit')
            ->first();

        return response()->json($rfq, 201);
    }

    // ── POST /api/admin/rfq/{id}/respond ─────────────────────────────────────
    // Staff logs a supplier's response (phone/email — not in-system by supplier)
    public function rfqRespond(Request $request, int $id)
    {
        $rfq = DB::table('rfq_requests')->where('rfq_id', $id)->first();
        if (!$rfq) {
            return response()->json(['message' => 'RFQ not found.'], 404);
        }
        if ($rfq->status !== 'open') {
            return response()->json(['message' => 'RFQ is already closed.'], 409);
        }

        $request->validate([
            'supplier_id'    => 'required|integer|exists:suppliers,supplier_id',
            'unit_price'     => 'required|numeric|min:0.01', // was min:0 — a supplier quote is never free
            'qty_available'  => 'required|numeric|min:0.01',
            'lead_time_days' => 'required|integer|min:1',
            'notes'          => 'nullable|string|max:1000',
        ]);

        $respId = DB::table('rfq_responses')->insertGetId([
            'rfq_id'         => $id,
            'supplier_id'    => $request->input('supplier_id'),
            'unit_price'     => $request->input('unit_price'),
            'qty_available'  => $request->input('qty_available'),
            'lead_time_days' => $request->input('lead_time_days'),
            'notes'          => $request->input('notes'),
            'responded_at'   => now(),
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        return response()->json(
            DB::table('rfq_responses')
                ->join('suppliers', 'rfq_responses.supplier_id', '=', 'suppliers.supplier_id')
                ->where('rfq_responses.response_id', $respId)
                ->select('rfq_responses.*', 'suppliers.supplier_name')
                ->first(),
            201
        );
    }

    // ── POST /api/admin/rfq/{id}/convert-po ──────────────────────────────────
    // Manager selects winning response → creates PO → closes RFQ
    public function rfqConvertToPO(Request $request, int $id)
    {
        $rfq = DB::table('rfq_requests')->where('rfq_id', $id)->first();
        if (!$rfq) {
            return response()->json(['message' => 'RFQ not found.'], 404);
        }

        $request->validate([
            'response_id'            => 'required|integer|exists:rfq_responses,response_id',
            'expected_delivery_date' => 'nullable|date',
            'notes'                  => 'nullable|string|max:500',
        ]);

        $response = DB::table('rfq_responses')
            ->where('response_id', $request->input('response_id'))
            ->where('rfq_id', $id)
            ->first();

        if (!$response) {
            return response()->json(['message' => 'Response does not belong to this RFQ.'], 422);
        }

        $total    = $rfq->qty_needed * $response->unit_price;
        $poNumber = 'PO-' . now()->format('Ymd') . '-' . strtoupper(substr(uniqid(), -5));

        $poId = DB::table('purchase_orders')->insertGetId([
            'po_number'              => $poNumber,
            'supplier_id'            => $response->supplier_id,
            'created_by'             => Auth::id(),
            'status'                 => 'sent',
            'total_amount'           => $total,
            'expected_delivery_date' => $request->input('expected_delivery_date'),
            'notes'                  => $request->input('notes'),
            'color_mismatch'         => 0,
            'color_confirmed'        => 0,
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);

        DB::table('purchase_order_items')->insert([
            'po_id'       => $poId,
            'material_id' => $rfq->material_id,
            'qty'         => $rfq->qty_needed,
            'unit_cost'   => $response->unit_price,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        // Close the RFQ
        DB::table('rfq_requests')
            ->where('rfq_id', $id)
            ->update(['status' => 'closed', 'updated_at' => now()]);

        Cache::forget('dashboard_stats');

        return response()->json([
            'message'   => "RFQ #{$id} converted to PO {$poNumber}.",
            'po_id'     => $poId,
            'po_number' => $poNumber,
        ], 201);
    }

    // ── POST /api/admin/rfq/{id}/close ────────────────────────────────────────
    public function rfqClose(int $id)
    {
        $updated = DB::table('rfq_requests')
            ->where('rfq_id', $id)
            ->update(['status' => 'closed', 'updated_at' => now()]);

        if (!$updated) {
            return response()->json(['message' => 'RFQ not found.'], 404);
        }

        return response()->json(['message' => 'RFQ closed.']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // SUPPLIERS (master data — no portal, no login)
    // ──────────────────────────────────────────────────────────────────────────

    // ── GET /api/admin/suppliers ──────────────────────────────────────────────
    public function suppliersIndex(Request $request)
    {
        $perPage = (int) $request->input('per_page', 20);

        return response()->json(
            DB::table('suppliers')
                ->where('is_active', 1)
                ->orderBy('supplier_name')
                ->paginate($perPage)
        );
    }

    // ── POST /api/admin/suppliers ─────────────────────────────────────────────
    public function suppliersStore(Request $request)
    {
        $request->validate([
            'supplier_name'   => 'required|string|max:150',
            'contact_person'  => 'nullable|string|max:100',
            'email'           => 'nullable|email|max:150',
            'phone'           => 'nullable|string|max:30',
            'address'         => 'nullable|string',
            'supplier_type'   => 'required|in:subcontract,direct',
            'lead_time_days'  => 'nullable|integer|min:1',
            'materials_supplied' => 'nullable|string',
            'payment_terms_with_supplier' => 'nullable|in:cash,net_30,net_60,not_specified',
        ]);

        $id = DB::table('suppliers')->insertGetId([
            'supplier_name'               => $request->input('supplier_name'),
            'contact_person'              => $request->input('contact_person'),
            'email'                       => $request->input('email'),
            'phone'                       => $request->input('phone'),
            'address'                     => $request->input('address'),
            'supplier_type'               => $request->input('supplier_type'),
            'materials_supplied'          => $request->input('materials_supplied'),
            'payment_terms_with_supplier' => $request->input('payment_terms_with_supplier', 'not_specified'),
            'lead_time_days'              => $request->input('lead_time_days'),
            'is_active'                   => 1,
            'created_at'                  => now(),
            'updated_at'                  => now(),
        ]);

        return response()->json(
            DB::table('suppliers')->where('supplier_id', $id)->first(),
            201
        );
    }

    // ── PUT /api/admin/suppliers/{id} ─────────────────────────────────────────
    public function suppliersUpdate(Request $request, int $id)
    {
        $exists = DB::table('suppliers')->where('supplier_id', $id)->exists();
        if (!$exists) {
            return response()->json(['message' => 'Supplier not found.'], 404);
        }

        $request->validate([
            'supplier_name'  => 'sometimes|string|max:150',
            'contact_person' => 'nullable|string|max:100',
            'email'          => 'nullable|email|max:150',
            'phone'          => 'nullable|string|max:30',
            'is_active'      => 'sometimes|boolean',
        ]);

        $fields = collect($request->only([
            'supplier_name', 'contact_person', 'email', 'phone', 'address',
            'supplier_type', 'materials_supplied', 'payment_terms_with_supplier',
            'lead_time_days', 'is_active', 'notes',
        ]))->filter(fn($v) => !is_null($v))->toArray();

        $fields['updated_at'] = now();
        DB::table('suppliers')->where('supplier_id', $id)->update($fields);

        return response()->json(DB::table('suppliers')->where('supplier_id', $id)->first());
    }

    // ──────────────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ──────────────────────────────────────────────────────────────────────────

    // Simple RGB ΔE approximation (CIE76-like on sRGB — good enough for swatch check)
    private function computeDeltaE(string $hex1, string $hex2): float
    {
        $r1 = hexdec(substr(ltrim($hex1, '#'), 0, 2));
        $g1 = hexdec(substr(ltrim($hex1, '#'), 2, 2));
        $b1 = hexdec(substr(ltrim($hex1, '#'), 4, 2));
        $r2 = hexdec(substr(ltrim($hex2, '#'), 0, 2));
        $g2 = hexdec(substr(ltrim($hex2, '#'), 2, 2));
        $b2 = hexdec(substr(ltrim($hex2, '#'), 4, 2));

        // Scale to 0–100 for a ΔE-like value
        return sqrt(
            pow(($r1 - $r2) / 2.55, 2) +
            pow(($g1 - $g2) / 2.55, 2) +
            pow(($b1 - $b2) / 2.55, 2)
        ) / sqrt(3);
    }

    private function notifyManagers(?int $orderId, string $message): void
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
                'type'       => 'purchase_order',
                'is_read'    => 0,
                'date_sent'  => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
