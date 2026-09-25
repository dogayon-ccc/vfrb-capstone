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
use App\Models\User;
use App\Notifications\OrderStatusNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class OrderController extends Controller
{
    // Mirrors SettingsController::logoDisk() exactly (Task B, Aug 31 2026) —
    // design_ref_file uploads were hardcoded to the local 'public' disk,
    // which Railway wipes on every redeploy (per DEPLOYMENT.md's ephemeral-
    // filesystem note). Cloudinary preferred when configured, 's3' as a
    // secondary fallback if that's ever finished, 'public' local-dev-only
    // as the last resort — same three-way priority, not a new mechanism.
    private function designRefDisk(): string
    {
        if (config('filesystems.disks.cloudinary.cloud')) {
            return 'cloudinary';
        }
        return config('filesystems.default') === 's3' ? 's3' : 'public';
    }

    // Resolves a stored design_ref_file path into a real, loadable URL on
    // whatever disk is currently configured. Matches SettingsController's
    // read-time resolution for logo_url — same known simplification: a
    // file uploaded under a since-changed disk config would resolve wrong.
    // Not solved here since SettingsController doesn't solve it either;
    // consistent with the existing convention rather than a new one.
    private function resolveDesignRefUrl($rawPath): ?string
    {
        if (!$rawPath) {
            return null;
        }
        return Storage::disk($this->designRefDisk())->url($rawPath);
    }

    // Resolves the Design Studio preview image for one order. Orders created
    // before 2026_09_16 have no preview file and still carry the base64
    // previewPng inside studio_config, so fall back to that rather than
    // showing them as having no design.
    private function resolveDesignPreviewUrl($order): ?string
    {
        if (!empty($order->client_design_preview_file)) {
            return $this->resolveDesignRefUrl($order->client_design_preview_file);
        }

        $cfg = is_array($order->studio_config)
            ? $order->studio_config
            : json_decode($order->studio_config ?? '', true);

        return $cfg['previewPng'] ?? null;
    }

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

        // FIX (Task B, Aug 31 2026): was returning the raw stored path,
        // leaving fileUrl.js's getStorageUrl() to guess how to build a
        // loadable URL from it — worked by luck when everything lived on
        // the local 'public' disk, breaks the moment designRefDisk()
        // resolves to 'cloudinary'. Resolve it here instead, same as
        // SettingsController does for logo_url.
        $order->client_design_ref_file = $this->resolveDesignRefUrl($order->client_design_ref_file);
        $order->design_preview_url = $this->resolveDesignPreviewUrl($order);

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
        // FIX (Sept 22 2026 — design-gate audit): client_design_notes was
        // 'nullable' here while OrderWizard.jsx's own validate() has
        // *always* required it (15+ chars, Step 0) before letting the
        // customer past Step 1. That made the requirement frontend-only —
        // a direct POST to this endpoint (curl/Postman/a modified client)
        // could create an order with a garment_type and a quantity and
        // nothing describing what to actually make. Mirroring the
        // frontend's own rule here, not inventing a stricter one: both
        // real submission paths (Studio-prefilled notes in the mount
        // effect, or manually typed) already clear 15 chars, so this
        // closes the bypass without changing behavior for any real
        // customer. It does NOT require studio_config or design_ref_file
        // specifically — the frontend never required either of those on
        // their own, only the description, so making that a new hard
        // requirement here would be a scope decision beyond "close the
        // bypass," not a bug fix.
        //
        // Also added: 'deadline' => 'after:today'. Nothing — frontend or
        // backend — previously rejected a past date; the <input
        // type="date"> has no min attribute and validate() never checks
        // it. A blank/omitted deadline is still fine (nullable); a
        // supplied one must be in the future.
        $request->validate([
            'garment_type'     => 'required|string|max:60',
            'quantity_ordered' => 'required|integer|min:100',
            'color'            => 'nullable|string|max:50',
            'order_type'       => 'nullable|in:direct,subcontract,bulk,rush',
            'sizing_type'      => 'nullable|in:standard,custom',
            'collar_type'      => 'nullable|string|max:60',
            'sleeve_type'      => 'nullable|string|max:60',
            'pocket_type'      => 'nullable|string|max:60',
            'deadline'         => 'nullable|date|after:today',
            'po_reference'     => 'nullable|string|max:100',
            'client_design_notes' => 'required|string|min:15',
            'studio_config'    => 'nullable|string',    // JSON string from canvas
            'design_ref_file'  => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'design_preview_file' => 'nullable|file|mimes:png|max:5120',
            'sizes'            => 'nullable|string',    // JSON string
            'measurements'     => 'nullable|string',    // JSON string
            'custom_qty'       => 'nullable|integer|min:0',
        ]);

        // Idempotency guard (Sept 22 2026): nothing previously stopped a
        // dropped network response + client retry from creating two
        // identical orders — the frontend's disabled={busy} on the submit
        // button doesn't survive a connection drop that happens before the
        // response arrives back. Cache::lock() runs on the 'file' cache
        // store (CACHE_STORE=file in .env); file-store atomic locks have
        // been supported since Laravel 9's FileLock, so this needs no
        // migration, no new table, and no Redis dependency.
        //
        // Deliberately per-user, not per-payload: hashing studio_config to
        // detect "the same order" risks false negatives from
        // non-deterministic JSON key ordering, and a real customer placing
        // two genuinely different bulk orders (100+ pcs, full 4-step
        // wizard each) inside the same 10-second window isn't a case this
        // needs to support. The lock is deliberately left to expire on its
        // own rather than released in a finally block — it's a short
        // debounce window on the act of submitting, not a mutex meant to
        // guard the whole method body.
        $lock = Cache::lock('order-submit:' . Auth::id(), 10);
        if (!$lock->get()) {
            return response()->json([
                'message' => 'Your previous order submission is still being processed. Please wait a moment before trying again.',
            ], 409);
        }

        // Size-breakdown-vs-total check (standard sizing only — custom sizing
        // has no per-size breakdown to sum). Runs BEFORE the order insert so
        // a mismatched breakdown never reaches the database at all.
        $sizingType = $request->input('sizing_type', 'standard');
        $sizesInput = json_decode($request->input('sizes', '{}'), true);
        if ($sizingType === 'standard' && is_array($sizesInput) && !empty($sizesInput)) {
            $sizeSum = array_sum(array_map('intval', $sizesInput));
            $qtyOrdered = (int) $request->input('quantity_ordered');
            if ($sizeSum !== $qtyOrdered) {
                // Release immediately: this is the customer correcting a typo,
                // not a duplicate submission — they shouldn't have to wait out
                // the 10-second debounce window just to fix a number and
                // resubmit right away.
                $lock->release();
                return response()->json([
                    'message' => "Size breakdown ({$sizeSum} pcs) doesn't match the total quantity ordered ({$qtyOrdered} pcs). They must add up exactly.",
                    'size_sum' => $sizeSum,
                    'quantity_ordered' => $qtyOrdered,
                ], 422);
            }
        }

        // Handle design reference file upload
        $refFilePath = null;
        if ($request->hasFile('design_ref_file')) {
            $refFilePath = $request->file('design_ref_file')
                ->store('design-refs', $this->designRefDisk());
        }

        // Design Studio preview PNG, uploaded as a real file by OrderWizard.
        $previewFilePath = null;
        if ($request->hasFile('design_preview_file')) {
            $previewFilePath = $request->file('design_preview_file')
                ->store('design-previews', $this->designRefDisk());
        }

        // Parse studio_config JSON string
        $studioConfig = null;
        if ($request->filled('studio_config')) {
            $decoded = json_decode($request->input('studio_config'), true);
            if (json_last_error() === JSON_ERROR_NONE) {
                // previewPng is a base64 data URL of the same image now stored
                // in $previewFilePath. Keeping both would put a ~150-500KB blob
                // in a column adminIndex() selects and decodes on every row.
                unset($decoded['previewPng']);
                $studioConfig = json_encode($decoded);
            }
        }

        // TRANSACTION FIX (Sept 25 audit): the order insert, its two
        // measurements inserts, and the manager-notification inserts used
        // to run as separate, unwrapped statements. A failure partway
        // through (e.g. the measurements insert) left a committed order row
        // with no size/measurement data and no notification — an orphaned,
        // effectively invisible order, since staff only ever get pointed at
        // new orders via that notification. Wrapping the whole write in one
        // transaction makes it all-or-nothing: any exception rolls every
        // insert back and the customer sees the failure instead of a "sent"
        // order nobody in the system knows exists.
        $orderId = DB::transaction(function () use ($request, $refFilePath, $previewFilePath, $studioConfig, $sizesInput) {
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
                'client_design_preview_file' => $previewFilePath,
                'studio_config'         => $studioConfig,
                'status'                => 'pending',
                'ai_recommendation_status' => 'not_requested',
                'qc_required'           => 1,
                'notes'                 => $request->input('special_notes'),
                'created_at'            => now(),
                'updated_at'            => now(),
            ]);

            // Store per-size quantities in measurements table if provided
            // (reuses $sizesInput, already decoded + validated above — no need to decode twice)
            $sizes = $sizesInput;
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

            return $orderId;
        });

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

        // Legacy orders (pre-2026_09_16) carry a base64 previewPng inside
        // studio_config. This list never renders it, so drop it from the
        // payload — 20 rows per page of ~150-500KB each otherwise.
        $result->getCollection()->transform(function ($o) {
            if ($o->studio_config) {
                $cfg = json_decode($o->studio_config, true);
                unset($cfg['previewPng']);
                $o->studio_config = $cfg;
            }
            $o->design_preview_url = $o->client_design_preview_file
                ? $this->resolveDesignRefUrl($o->client_design_preview_file)
                : null;
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

        // FIX (Task B, Aug 31 2026): same resolution as customerShow() —
        // this one feeds BOTH adminShow() and downloadInvoicePdf() (see
        // this method's own header comment), so fixing it here covers the
        // admin order-detail screen and the printed invoice in one place.
        $order->client_design_ref_file = $this->resolveDesignRefUrl($order->client_design_ref_file);
        $order->design_preview_url = $this->resolveDesignPreviewUrl($order);

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

        if ($request->hasAny(['status', 'agreed_total']) && !$request->user()->isManager()) {
            return response()->json(['message' => 'Only a manager can change order status or total.'], 403);
        }

        $request->validate([
            'status' => 'sometimes|in:pending,confirmed,pattern,segregation,cutting,sewing,qc,pressing,packing,completed,cancelled',
            'negotiated_delivery_date' => 'nullable|date',
            'notes' => 'nullable|string',
            'agreed_total' => 'nullable|numeric|min:0',
        ]);

        $fields = collect($request->only(['status', 'negotiated_delivery_date', 'notes']))
            ->filter(fn($v) => !is_null($v))
            ->toArray();

        // agreed_total is write-once: later changes go through payments.
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

            // Real email (Aug 25 2026) — deliberately restricted to only
            // these two statuses, not every value the enum allows. A
            // manager manually setting a production stage through this
            // same endpoint is covered separately by
            // StageAdvancedNotification (ProductionController), so this
            // doesn't send a duplicate email for what's functionally the
            // same underlying event.
            //
            // Wrapped in try/catch deliberately: the order status change
            // above already succeeded in the database by this point. If
            // Mailtrap/mail sending has a hiccup, that should never surface
            // as a failed order-confirm action to the manager — email is
            // best-effort here, not a blocking dependency of the real
            // business action.
            if (in_array($fields['status'], ['confirmed', 'cancelled'], true)) {
                try {
                    $customer = User::find($order->user_id);
                    if ($customer) {
                        $customer->notify(new OrderStatusNotification(
                            $id,
                            $fields['status'],
                            $fields['status'] === 'cancelled' ? $request->input('notes') : null,
                        ));
                    }
                } catch (\Throwable $e) {
                    \Log::warning("OrderStatusNotification failed for order #{$id}: " . $e->getMessage());
                }
            }
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