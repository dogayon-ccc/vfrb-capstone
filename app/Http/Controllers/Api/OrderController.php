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
//   material_recommendations (customer view = MaterialRecommendation::CUSTOMER_COLUMNS; no quantity columns)
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
use App\Models\AuditLog;
use App\Models\MaterialRecommendation;
use App\Models\Order;
use App\Models\User;
use App\Notifications\OrderStatusNotification;
use App\Notifications\PortalMailNotification;
use App\Services\BillingService;
use App\Services\MaterialShortageService;
use App\Support\EmailNotify;
use App\Support\FileUrl;
use App\Support\StudioSet;
use App\Support\UploadDisk;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

class OrderController extends Controller
{
    // Direct/bulk: 80% down at confirmation; subcontract: paid in full after delivery.
    private static function paymentTermsFor(string $orderType): string
    {
        return $orderType === 'subcontract' ? 'full' : 'down';
    }

    // Mirrors SettingsController::logoDisk() exactly (Task B, Aug 31 2026) —
    // design_ref_file uploads were hardcoded to the local 'public' disk,
    // which Railway wipes on every redeploy (per DEPLOYMENT.md's ephemeral-
    // filesystem note). Cloudinary preferred when configured, 's3' as a
    // secondary fallback if that's ever finished, 'public' local-dev-only
    // as the last resort — same three-way priority, not a new mechanism.
    private function designRefDisk(): string
    {
        return UploadDisk::name();
    }

    // Resolves a stored design_ref_file path into a real, loadable URL on
    // whatever disk is currently configured. Matches SettingsController's
    // read-time resolution for logo_url — same known simplification: a
    // file uploaded under a since-changed disk config would resolve wrong.
    // Not solved here since SettingsController doesn't solve it either;
    // consistent with the existing convention rather than a new one.
    private function resolveDesignRefUrl($rawPath): ?string
    {
        if (! $rawPath) {
            return null;
        }

        return FileUrl::for($this->designRefDisk(), $rawPath);
    }

    // Resolves the Design Studio preview image for one order. Orders created
    // before 2026_09_16 have no preview file and still carry the base64
    // previewPng inside studio_config, so fall back to that rather than
    // showing them as having no design.
    private function resolveDesignPreviewUrl($order): ?string
    {
        if (! empty($order->client_design_preview_file)) {
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
        $userId = Auth::id();
        $status = $request->input('status', '');
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

        $page = $query->paginate($perPage);
        $page->getCollection()->transform(fn ($o) => self::clientMoney(self::clientStatus($o)));

        return response()->json($page);
    }

    // Clients see pending / confirmed / in_production / completed / cancelled; stage names stay inside VFRB.
    public static function clientStatus($o)
    {
        if (isset($o->status) && ! in_array($o->status, ['pending', 'confirmed', 'completed', 'cancelled'], true)) {
            $o->status = 'in_production';
        }

        return $o;
    }

    // Customers never receive money fields; prices are agreed off-platform and the proposal is date-only.
    private static function clientMoney($o)
    {
        unset($o->agreed_total, $o->discount_rate, $o->down_payment_amount);

        return $o;
    }

    // ── GET /api/customer/orders/{id} ─────────────────────────────────────────
    // OrderDetail.jsx: { order, recommendations, production_stages }
    public function customerShow(int $id)
    {
        $order = DB::table('orders')
            ->where('order_id', $id)
            ->where('user_id', Auth::id())
            ->first();

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $order = self::clientMoney(self::clientStatus($order));

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
        $order->design_3d_snapshot_url = $this->resolveDesignRefUrl($order->client_design_3d_snapshot_file ?? null);

        // Attach AI recommendations
        $recommendations = DB::table('material_recommendations')
            ->where('order_id', $id)
            ->orderBy('display_order')
            ->get(MaterialRecommendation::CUSTOMER_COLUMNS);

        return response()->json([
            'order' => $order,
            'recommendations' => $recommendations,
            'production_stages' => (object) [], // hidden from clients
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
        // Date, total, status and down payment are VFRB's to set (via propose / down-payment), never the customer's.
        $blocked = array_values(array_filter(Order::MANAGER_ONLY_FIELDS, fn ($f) => $request->has($f)));
        if ($blocked) {
            return response()->json([
                'message' => 'Only VFRB can set the promised date, total, status or down payment. Remove: '.implode(', ', $blocked).'.',
                'errors' => array_fill_keys($blocked, ['Only VFRB can set this field.']),
            ], 422);
        }

        $request->validate([
            'garment_type' => 'required|string|max:60',
            'quantity_ordered' => 'required|integer|min:100',
            'fabric_type' => 'nullable|string|max:100',
            'delivery_address' => 'nullable|string|max:1000',
            'color' => 'nullable|string|max:50',
            'order_type' => 'nullable|in:direct,subcontract,bulk', // no rush orders
            'sizing_type' => 'nullable|in:standard,custom',
            'collar_type' => 'nullable|string|max:60',
            'sleeve_type' => 'nullable|string|max:60',
            'pocket_type' => 'nullable|string|max:60',
            // Optional preferred date (deadline is the old name); VFRB confirms or changes it via propose.
            'preferred_date' => 'nullable|date|after_or_equal:'.now()->addDays((int) config('vfrb.min_promised_days', 7))->toDateString(),
            'deadline' => 'nullable|date|after_or_equal:'.now()->addDays((int) config('vfrb.min_promised_days', 7))->toDateString(),
            'po_reference' => 'nullable|string|max:100',
            'client_design_notes' => 'required|string|min:15',
            'studio_config' => 'nullable|string',    // JSON string from canvas
            'design_ref_file' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'design_preview_file' => 'nullable|file|mimes:png|max:5120',
            'design_3d_snapshot_file' => 'nullable|file|mimes:png|max:5120',
            'sizes' => 'nullable|string',    // JSON string
            'measurements' => 'nullable|string',    // JSON string
            'custom_qty' => 'nullable|integer|min:0',
        ], [
            'quantity_ordered.min' => 'The minimum order is 100 pieces.',
            'order_type.in' => 'VFRB does not take rush orders. Choose direct, subcontract or bulk.',
            'preferred_date.after_or_equal' => 'The preferred date must be at least '.(int) config('vfrb.min_promised_days', 7).' days from today. VFRB does not take rush orders.',
            'deadline.after_or_equal' => 'The preferred date must be at least '.(int) config('vfrb.min_promised_days', 7).' days from today. VFRB does not take rush orders.',
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
        // A uniform set submits one order per piece back to back, so each piece of a set gets its own lock;
        // a retry of the same piece still hits the same key.
        $setPart = json_decode((string) $request->input('studio_config', ''), true)['uniformSet'] ?? null;
        $setKey = is_array($setPart) && is_string($setPart['id'] ?? null) && in_array($setPart['role'] ?? null, ['top', 'bottom'], true)
            ? ':set:'.substr(preg_replace('/[^A-Za-z0-9-]/', '', $setPart['id']), 0, 64).':'.$setPart['role'] : '';
        $lock = Cache::lock('order-submit:'.Auth::id().$setKey, 10);
        if (! $lock->get()) {
            return response()->json([
                'message' => 'Your previous order submission is still being processed. Please wait a moment before trying again.',
            ], 409);
        }

        // Size-breakdown-vs-total check (standard sizing only — custom sizing
        // has no per-size breakdown to sum). Runs BEFORE the order insert so
        // a mismatched breakdown never reaches the database at all.
        $sizingType = $request->input('sizing_type', 'standard');
        $sizesInput = json_decode($request->input('sizes', '{}'), true);

        $invalid = null;
        if (! is_array($sizesInput) || collect($sizesInput)->contains(fn ($q) => ! is_numeric($q) || $q < 0 || (int) $q != $q)) {
            $invalid = 'Size breakdown must be a JSON object of whole, non-negative quantities.';
        } elseif ($request->filled('studio_config') && ! is_array(json_decode($request->input('studio_config'), true))) {
            $invalid = 'The saved design could not be read. Re-open it in Design Studio and try again.';
        }
        if ($invalid) {
            $lock->release();

            return response()->json(['message' => $invalid], 422);
        }

        if ($sizingType === 'standard' && ! empty($sizesInput)) {
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

        // Optional 3D (approx.) snapshot the customer took in the wizard; skipped until migration 2026_10_22_000001 has run.
        $snapshotFilePath = null;
        if ($request->hasFile('design_3d_snapshot_file') && Schema::hasColumn('orders', 'client_design_3d_snapshot_file')) {
            $snapshotFilePath = $request->file('design_3d_snapshot_file')
                ->store('design-previews', $this->designRefDisk());
        }

        // Parse studio_config JSON string
        $studioConfig = null;
        if ($request->filled('studio_config')) {
            // previewPng duplicates the stored preview file; keeping it bloats a column adminIndex() decodes per row.
            $decoded = json_decode($request->input('studio_config'), true);
            unset($decoded['previewPng']);
            $studioConfig = json_encode($decoded);
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
        $orderId = DB::transaction(function () use ($request, $refFilePath, $previewFilePath, $snapshotFilePath, $studioConfig, $sizesInput) {
            $orderId = DB::table('orders')->insertGetId(($snapshotFilePath ? ['client_design_3d_snapshot_file' => $snapshotFilePath] : []) + [
                'user_id' => Auth::id(),
                'garment_type' => $request->input('garment_type'),
                'collar_type' => $request->input('collar_type'),
                'sleeve_type' => $request->input('sleeve_type'),
                'pocket_type' => $request->input('pocket_type'),
                'color' => $request->input('color'),
                'quantity_ordered' => (int) $request->input('quantity_ordered'),
                'order_type' => 'direct', // customers place direct orders; a manager marks subcontract ones
                'payment_terms' => self::paymentTermsFor('direct'),
                'sizing_type' => $request->input('sizing_type', 'standard'),
                'target_delivery_date' => $request->input('preferred_date') ?: $request->input('deadline'),
                'fabric_type' => $request->input('fabric_type'),
                'delivery_address' => $request->input('delivery_address'),
                'po_reference' => $request->input('po_reference'),
                'client_design_notes' => $request->input('client_design_notes'),
                'client_design_ref_file' => $refFilePath,
                'client_design_preview_file' => $previewFilePath,
                'studio_config' => $studioConfig,
                'status' => 'pending',
                'ai_recommendation_status' => 'not_requested',
                'qc_required' => 1,
                'notes' => $request->input('special_notes'),
                'created_at' => now(),
                'updated_at' => now(),
            ], 'order_id');

            // Store per-size quantities in measurements table if provided
            // (reuses $sizesInput, already decoded + validated above — no need to decode twice)
            $sizes = $sizesInput;
            if (is_array($sizes)) {
                foreach ($sizes as $sizeLabel => $qty) {
                    if ($qty > 0) {
                        DB::table('measurements')->insert([
                            'order_id' => $orderId,
                            'user_id' => Auth::id(),
                            'type' => 'standard',
                            'size_label' => strtoupper($sizeLabel),
                            'qty' => (int) $qty,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            }

            // Store custom measurements if sizing_type = custom
            $customMeasurements = json_decode($request->input('measurements', '{}'), true);
            if (is_array($customMeasurements) && ! empty($customMeasurements)) {
                DB::table('measurements')->insert([
                    'order_id' => $orderId,
                    'user_id' => Auth::id(),
                    'type' => 'custom',
                    'size_label' => 'custom',
                    'chest' => $customMeasurements['chest'] ?? null,
                    'waist' => $customMeasurements['waist'] ?? null,
                    'hip' => $customMeasurements['hip'] ?? null,
                    'sleeve_length' => $customMeasurements['sleeve_length'] ?? null,
                    'neck' => $customMeasurements['neck'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Notify managers of new order (in-app now, email after the response)
            $managerIds = $this->notifyManagers($orderId, "New order #$orderId submitted by customer.");
            EmailNotify::users($managerIds, new PortalMailNotification(
                "New VFRB order #{$orderId}", ["A customer submitted order #{$orderId}. It is waiting for review and confirmation."],
                'Review order', "/admin/orders/{$orderId}"));

            return $orderId;
        });

        Cache::forget('dashboard_stats');

        $order = DB::table('orders')->where('order_id', $orderId)->first();
        if ($order->studio_config) {
            $order->studio_config = json_decode($order->studio_config, true);
        }

        return response()->json(['order' => self::clientMoney(self::clientStatus($order))], 201);
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
            'total_orders' => $totalOrders,
            'in_production' => $inProduction,
            'completed_orders' => $completedOrders,
        ]);
    }

    // ── GET /api/admin/orders ─────────────────────────────────────────────────
    // Orders.jsx, QCChecklist.jsx, ProductionTracking.jsx, SalesTransactions.jsx
    // Supports ?status=qc, ?status=pattern,cutting,sewing, ?per_page=50
    public function adminIndex(Request $request)
    {
        $status = $request->input('status', '');
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
                $statuses = ['pattern', 'segregation', 'cutting', 'sewing', 'qc', 'pressing', 'packing'];
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
        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $tracking = DB::table('order_production_tracking')
            ->where('order_id', $id)->get()->keyBy('stage');

        return response()->json([
            'order' => $order,
            'tracking' => $tracking,
        ]);
    }

    // GET /api/admin/orders/{id}/spec-sheet: price-free design spec sheet built from studio_config.
    public function adminSpecSheet(int $id)
    {
        return $this->specSheet($id, null);
    }

    // GET /api/customer/orders/{id}/spec-sheet: same sheet, own orders only.
    public function customerSpecSheet(int $id)
    {
        return $this->specSheet($id, Auth::id());
    }

    private function specSheet(int $id, ?int $userId)
    {
        $order = DB::table('orders')
            ->join('users', 'orders.user_id', '=', 'users.user_id')
            ->where('orders.order_id', $id)
            ->when($userId, fn ($q) => $q->where('orders.user_id', $userId))
            ->first(['orders.order_id', 'orders.status', 'orders.quantity_ordered', 'orders.sizing_type', 'orders.studio_config',
                'orders.client_design_preview_file', 'users.name as customer_name', 'users.organization_name']);
        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }
        $cfg = json_decode($order->studio_config ?? '', true);
        if (! is_array($cfg)) {
            return response()->json(['message' => 'This order has no Design Studio design.'], 422);
        }

        $layers = [];
        foreach (['Front' => 'frontOverlays', 'Back' => 'backOverlays'] as $face => $key) {
            foreach ((array) ($cfg[$key] ?? []) as $o) {
                if (! is_array($o) || empty($o['__logo']) && empty($o['__text'])) continue;
                $sx = (float) ($o['scaleX'] ?? 1);
                $sy = (float) ($o['scaleY'] ?? 1);
                $layers[] = [
                    'face'  => $face,
                    'kind'  => ! empty($o['__text']) ? 'Text' : (! empty($o['__artwork']) ? 'Artwork' : 'Logo'),
                    'text'  => $o['text'] ?? null,
                    'font'  => $o['fontFamily'] ?? null,
                    'fill'  => is_string($o['fill'] ?? null) ? strtoupper($o['fill']) : null,
                    'x'     => round((float) ($o['left'] ?? 0)),
                    'y'     => round((float) ($o['top'] ?? 0)),
                    'w'     => round((float) ($o['width'] ?? 0) * $sx),
                    'h'     => round((float) ($o['height'] ?? 0) * $sy),
                    'angle' => round((float) ($o['angle'] ?? 0)),
                    'img'   => empty($o['__text']) ? $this->pdfImage($o['src'] ?? null) : null,
                ];
            }
        }

        $pdf = Pdf::loadView('pdf.spec-sheet', [
            'order'    => $order,
            'cfg'      => $cfg,
            'zones'    => array_filter((array) ($cfg['colors'] ?? []), fn ($hex) => is_string($hex) && $hex !== ''),
            'layers'   => $layers,
            'sizes'    => DB::table('measurements')->where('order_id', $id)->orderBy('measurement_id')->get(['type', 'size_label', 'qty']),
            'preview'  => $this->pdfImage($order->client_design_preview_file ? $this->resolveDesignRefUrl($order->client_design_preview_file) : ($cfg['previewPng'] ?? null)),
            'printedAt' => now()->format('F d, Y g:i A'),
        ])->setPaper('a4', 'portrait');

        return $pdf->download("VFRB-Spec-Sheet-ORD-{$id}.pdf");
    }

    // dompdf has remote loading off, so remote images are fetched here and embedded; data URLs pass through.
    private function pdfImage(?string $src): ?string
    {
        if (! $src) return null;
        if (str_starts_with($src, 'data:image/')) return $src;
        if (! preg_match('#^https?://#i', $src)) return null;
        try {
            $res = Http::timeout(8)->get($src);
            $type = $res->header('Content-Type');
            return $res->successful() && str_starts_with($type, 'image/') ? "data:{$type};base64,".base64_encode($res->body()) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    // ── GET /api/admin/orders/{id}/invoice-pdf ────────────────────────────────
    // Real, downloadable invoice — barryvdh/laravel-dompdf was already in
    // composer.json but wired to ZERO routes before this. Reuses the exact
    // same data buildOrderDetail() feeds to the on-screen Invoice.jsx, so the
    // PDF and the screen can never silently disagree with each other.
    public function downloadInvoicePdf(int $id)
    {
        $order = $this->buildOrderDetail($id);
        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        // Blade view uses array access ($order['field']) — flatten the
        // mixed stdClass/array structure buildOrderDetail() returns.
        $orderArray = json_decode(json_encode($order), true);

        $pdf = Pdf::loadView('pdf.invoice', [
            'order' => $orderArray,
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

        if (! $order) {
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
        $order->design_3d_snapshot_url = $this->resolveDesignRefUrl($order->client_design_3d_snapshot_file ?? null);

        // Nested `user` object — Invoice.jsx / invoice.blade.php read
        // order.user.{name, organization_name, email, contact_number}.
        $order->user = [
            'name' => $order->customer_name,
            'organization_name' => $order->organization_name,
            'email' => $order->customer_email,
            'contact_number' => $order->customer_contact,
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
                    'material_name' => $rec->linked_material_name,
                    'unit' => $rec->material_unit,
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

        // Customer-submitted size breakdown (measurements.order_id, size_label, qty).
        // Read-only; lets admin review what was actually ordered per size.
        $order->measurements = DB::table('measurements')
            ->where('order_id', $id)
            ->orderBy('measurement_id')
            ->get(['measurement_id', 'type', 'size_label', 'qty', 'neck', 'chest', 'waist', 'hip', 'sleeve_length']);

        return $order;
    }

    private const ADMIN_STATUS_TRANSITIONS = [
        'pending' => ['confirmed', 'cancelled'],
        'confirmed' => ['cancelled'],
    ];

    // ── PATCH /api/admin/orders/{id} ─────────────────────────────────────────
    // Admin updates order status (confirm, cancel)
    public function adminUpdate(Request $request, int $id)
    {
        $order = DB::table('orders')->where('order_id', $id)->first();
        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        // Every field this endpoint changes is a manager decision (review & confirm, cancel with a reason).
        if ($request->hasAny(['status', 'agreed_total', 'negotiated_delivery_date', 'notes', 'order_type']) && ! $request->user()->isManager()) {
            return response()->json(['message' => 'Only an Administrator can change the order status, total, delivery date or review notes.'], 403);
        }

        $request->validate([
            'status' => 'sometimes|in:pending,confirmed,pattern,segregation,cutting,sewing,qc,pressing,packing,completed,cancelled',
            'negotiated_delivery_date' => 'nullable|date',
            'notes' => 'nullable|string',
            'agreed_total' => 'nullable|numeric|min:0',
            'order_type' => 'sometimes|in:direct,subcontract,bulk',
        ]);

        if (in_array($order->status, ['completed', 'cancelled'], true)) {
            return response()->json(['message' => "Order #{$id} is {$order->status} and can no longer be changed."], 409);
        }

        $target = $request->input('status');
        if ($target !== null && $target !== $order->status
            && ! in_array($target, self::ADMIN_STATUS_TRANSITIONS[$order->status] ?? [], true)) {
            return response()->json(['message' => "A {$order->status} order cannot be set to {$target} here. Use the production steps to advance it."], 422);
        }

        // The customer sees the cancel reason, so one is required.
        if ($target === 'cancelled' && $order->status !== 'cancelled' && ! $request->filled('notes')) {
            return response()->json(['message' => 'Give a reason for cancelling; the customer will see it.', 'errors' => ['notes' => ['A cancel reason is required.']]], 422);
        }
        // The payment rule follows order_type, so it is fixed once the down payment is in.
        if ($request->filled('order_type') && $request->input('order_type') !== $order->order_type && ! empty($order->down_payment_received_at)) {
            return response()->json(['message' => 'The order source cannot change after the down payment is recorded.'], 422);
        }
        if ($target === 'confirmed' && $order->status !== 'confirmed'
            && ($err = Order::promisedDateError($order->promised_date ?? null))) {
            return response()->json(['message' => $err], 422);
        }

        // `notes` is the manager's message to the customer (sent with the status change below); orders.notes keeps the customer's own notes.
        $fields = collect($request->only(['status', 'negotiated_delivery_date', 'order_type']))
            ->filter(fn ($v) => ! is_null($v))
            ->reject(fn ($v, $k) => $k === 'status' && $v === $order->status)
            ->toArray();
        if (isset($fields['order_type'])) {
            $fields['payment_terms'] = self::paymentTermsFor($fields['order_type']);
        }

        // agreed_total is write-once: later changes go through payments.
        if ($request->filled('agreed_total') && $order->agreed_total === null) {
            $fields['agreed_total'] = $request->input('agreed_total');
        }

        $fields['updated_at'] = now();
        DB::table('orders')->where('order_id', $id)->update($fields);

        if (isset($fields['status'])) {
            AuditLog::record('order_status_changed', 'orders', $id);
        }
        if (isset($fields['agreed_total'])) {
            AuditLog::record('order_total_set', 'orders', $id);
        }

        if (isset($fields['status'])) {
            $statusMessage = $fields['status'] === 'cancelled' && $request->filled('notes')
                ? "Your order #{$id} was cancelled. Reason: {$request->input('notes')}"
                : "Your order #{$id} status has been updated to ".ucfirst($fields['status']).'.'
                    .($fields['status'] === 'confirmed' && $request->filled('notes') ? " Note from VFRB: {$request->input('notes')}" : '');
            // Production stages (pattern ... packing) are never sent to the client.
            if (in_array($fields['status'], ['confirmed', 'completed', 'cancelled'], true)) {
                $this->notifyCustomer($order->user_id, $id, $statusMessage);
            }
            if ($fields['status'] === 'cancelled') {
                $this->postOrderMessage($id, $request->user()->user_id, "Order cancelled by VFRB. Reason: {$request->input('notes')}");
            }

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
                    \Log::warning("OrderStatusNotification failed for order #{$id}: ".$e->getMessage());
                }
            }

            if ($fields['status'] === 'confirmed') {
                StudioSet::confirmPendingSiblings($id);
            }
        }

        Cache::forget('dashboard_stats');

        $response = (array) DB::table('orders')->where('order_id', $id)->first();
        if (($fields['status'] ?? null) === 'confirmed') {
            $response['material_warnings'] = app(MaterialShortageService::class)->forOrder($id);
        }

        return response()->json($response);
    }

    // ── GET /api/admin/orders/{id}/suggested-date?lead_days= ──────────────────
    // Manager: suggested promised date from config('vfrb.lead_days_per_100'); lead_days overrides it.
    public function suggestedDate(Request $request, int $id)
    {
        $request->validate(['lead_days' => 'nullable|integer|min:1|max:365']);
        $order = DB::table('orders')->where('order_id', $id)->first();
        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $lead = $request->filled('lead_days') ? (int) $request->input('lead_days') : (int) config('vfrb.lead_days_per_100', 14);

        return response()->json([
            'suggested_promised_date' => Order::suggestedPromisedDate((int) $order->quantity_ordered, $lead)->toDateString(),
            'lead_days_per_100' => $lead,
        ]);
    }

    // ── POST /api/admin/orders/{id}/propose ───────────────────────────────────
    // Manager: proposes promised_date + total with a note (fabric availability, cheaper/better alternative, reason).
    public function propose(Request $request, int $id)
    {
        $order = DB::table('orders')->where('order_id', $id)->first();
        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $request->validate([
            'promised_date' => 'nullable|date|after:today',
            'lead_days' => 'nullable|integer|min:1|max:365',
            'total' => 'required|numeric|min:0',
            'proposal_note' => 'required|string|min:10|max:5000',
        ], [
            'proposal_note.required' => 'Add a note for the customer: fabric availability, any cheaper or better alternative, and the reason for the date and total.',
        ]);

        if ($order->status !== 'pending') {
            return response()->json(['message' => "Order #{$id} is {$order->status}; proposals can only be sent for pending orders."], 422);
        }
        if (($order->proposal_status ?? null) === 'accepted') {
            return response()->json(['message' => "The customer already accepted a proposal for order #{$id}."], 422);
        }

        $promised = $request->input('promised_date')
            ?: ($order->target_delivery_date ? substr((string) $order->target_delivery_date, 0, 10) : null)
            ?: Order::suggestedPromisedDate((int) $order->quantity_ordered, $request->filled('lead_days') ? (int) $request->input('lead_days') : null)->toDateString();

        if ($err = Order::promisedDateError($promised)) {
            return response()->json(['message' => $err, 'errors' => ['promised_date' => [$err]]], 422);
        }

        DB::table('orders')->where('order_id', $id)->update([
            'promised_date' => $promised,
            'agreed_total' => $request->input('total'),
            'proposal_note' => $request->input('proposal_note'),
            'proposal_status' => 'pending',
            'updated_at' => now(),
        ]);
        AuditLog::record('order_proposal_sent', 'orders', $id);

        $this->notifyCustomer($order->user_id, $id, "VFRB sent a proposal for order #{$id}: delivery by {$promised}. Please accept or decline.");
        EmailNotify::users([$order->user_id], new PortalMailNotification(
            "Proposal for your VFRB order #{$id}",
            ["Promised delivery date: {$promised}", "Note from VFRB: {$request->input('proposal_note')}", 'Please accept or decline the proposal in the portal.'],
            'Review proposal', "/customer/orders/{$id}"));

        Cache::forget('dashboard_stats');

        return response()->json(['message' => "Proposal sent for order #{$id}.", 'order' => DB::table('orders')->where('order_id', $id)->first()]);
    }

    // ── POST /api/admin/orders/{id}/down-payment ──────────────────────────────
    // Manager: records the down payment; production cannot start before this.
    public function recordDownPayment(Request $request, int $id)
    {
        $order = DB::table('orders')->where('order_id', $id)->first();
        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }
        $request->validate([
            'down_payment_amount' => 'required|numeric|min:0.01',
            'payment_method' => 'nullable|in:cash,gcash,ewallet,bank_transfer',
            'reference_no' => 'nullable|string|max:50',
        ]);

        if (in_array($order->status, ['completed', 'cancelled'], true)) {
            return response()->json(['message' => "Order #{$id} is {$order->status}; a down payment can no longer be recorded."], 422);
        }
        if (! empty($order->down_payment_received_at)) {
            return response()->json(['message' => "The down payment for order #{$id} was already recorded."], 422);
        }

        // Direct/bulk orders: at least 80% down, 20% on delivery. Subcontract orders
        // have no down payment: they are invoiced per delivery and paid after delivery.
        if ($order->order_type === 'subcontract') {
            return response()->json(['message' => "Subcontract order #{$id} is paid after delivery; no down payment is recorded."], 422);
        }
        if ($order->agreed_total === null) {
            return response()->json(['message' => "Set the order total (send a proposal) before recording a payment for order #{$id}."], 422);
        }
        $total = round((float) $order->agreed_total, 2);
        $amount = round((float) $request->input('down_payment_amount'), 2);
        $min = round($total * 0.8, 2);
        if ($amount < $min || $amount > $total) {
            $err = 'Direct orders need at least 80% down: between ₱'.number_format($min, 2).' and ₱'.number_format($total, 2).'.';

            return response()->json(['message' => $err, 'errors' => ['down_payment_amount' => [$err]]], 422);
        }

        DB::transaction(function () use ($request, $id, $amount) {
            DB::table('orders')->where('order_id', $id)->update([
                'down_payment_amount' => $request->input('down_payment_amount'),
                'down_payment_received_at' => now(),
                'updated_at' => now(),
            ]);
            AuditLog::record('order_down_payment_received', 'orders', $id);

            // Receipt only when no payment was logged for this order yet, so a manual entry is never doubled.
            if (! DB::table('sales_transactions')->where('order_id', $id)->exists()) {
                app(BillingService::class)->recordPayment($id, $amount, $request->input('payment_method', 'cash'), 'down_payment',
                    null, 'Down payment', null, (int) $request->user()->user_id, $request->input('reference_no'));
            }
        });
        $this->notifyCustomer($order->user_id, $id, "VFRB received your down payment for order #{$id}.");

        return response()->json(['message' => "Down payment recorded for order #{$id}.", 'order' => DB::table('orders')->where('order_id', $id)->first()]);
    }

    // ── POST /api/customer/orders/{id}/accept ─────────────────────────────────
    public function customerAcceptProposal(int $id)
    {
        $order = $this->customerProposalOrder($id);
        if ($order instanceof JsonResponse) {
            return $order;
        }
        // Confirmation happens now, so the 7-day rule is checked against today.
        if ($err = Order::promisedDateError($order->promised_date)) {
            return response()->json(['message' => $err.' Please message VFRB for a new date.'], 422);
        }

        DB::table('orders')->where('order_id', $id)->update([
            'proposal_status' => 'accepted',
            'status' => 'confirmed',
            'updated_at' => now(),
        ]);
        AuditLog::record('order_status_changed', 'orders', $id);

        $stockNote = app(MaterialShortageService::class)->summary($id);
        $managerIds = $this->notifyManagers($id, "Customer accepted the proposal for order #{$id}. Order confirmed; waiting for the down payment.".($stockNote ? " {$stockNote}" : ''));
        EmailNotify::users($managerIds, new PortalMailNotification(
            "Proposal accepted: order #{$id}", ["The customer accepted the proposal for order #{$id}. Record the down payment before production starts."],
            'Open order', "/admin/orders/{$id}"));
        EmailNotify::users([$order->user_id], new OrderStatusNotification($id, 'confirmed'));

        Cache::forget('dashboard_stats');

        return response()->json(['message' => "Order #{$id} confirmed. Production starts once VFRB receives the down payment.", 'order' => self::clientMoney(self::clientStatus(DB::table('orders')->where('order_id', $id)->first()))]);
    }

    // ── POST /api/customer/orders/{id}/decline { reason } ─────────────────────
    public function customerDeclineProposal(Request $request, int $id)
    {
        $request->validate(['reason' => 'required|string|min:5|max:2000'], [
            'reason.required' => 'Tell VFRB why you are declining so they can send a better proposal.',
        ]);
        $order = $this->customerProposalOrder($id);
        if ($order instanceof JsonResponse) {
            return $order;
        }

        DB::table('orders')->where('order_id', $id)->update(['proposal_status' => 'declined', 'updated_at' => now()]);
        AuditLog::record('order_proposal_declined', 'orders', $id);

        // Kept in the order's message thread so the customer and VFRB both see the reason.
        $this->postOrderMessage($id, Auth::id(), "Proposal declined. Reason: {$request->input('reason')}");
        $managerIds = $this->notifyManagers($id, "Customer declined the proposal for order #{$id}. Reason: {$request->input('reason')}");
        EmailNotify::users($managerIds, new PortalMailNotification(
            "Proposal declined: order #{$id}", ["The customer declined the proposal for order #{$id}.", "Reason: {$request->input('reason')}"],
            'Open order', "/admin/orders/{$id}"));

        return response()->json(['message' => 'Proposal declined. VFRB has been told your reason.', 'order' => self::clientMoney(self::clientStatus(DB::table('orders')->where('order_id', $id)->first()))]);
    }

    // ── POST /api/customer/orders/{id}/message { body } ───────────────────────
    // Same thread, ownership check and staff notifications as MessageController::customerStore.
    public function customerProposalMessage(Request $request, int $id)
    {
        $request->merge(['order_id' => $id]);

        return app(MessageController::class)->customerStore($request);
    }

    // Owned, pending order with a pending proposal — or the 404/422 response to return.
    private function customerProposalOrder(int $id)
    {
        $order = DB::table('orders')->where('order_id', $id)->where('user_id', Auth::id())->first();
        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }
        if ($order->status !== 'pending' || ($order->proposal_status ?? null) !== 'pending') {
            return response()->json(['message' => "There is no open proposal for order #{$id}."], 422);
        }

        return $order;
    }

    private function postOrderMessage(int $orderId, int $senderId, string $body): void
    {
        DB::table('order_messages')->insert([
            'order_id' => $orderId,
            'sender_id' => $senderId,
            'body' => $body,
            'is_read' => 0,
            'sent_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ── Private helpers ───────────────────────────────────────────────────────
    private function notifyManagers(int $orderId, string $message): array
    {
        $now = now();
        $managers = DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('roles.name', 'manager')
            ->pluck('model_has_roles.model_id');

        foreach ($managers as $uid) {
            DB::table('notifications')->insert([
                'user_id' => $uid,
                'order_id' => $orderId,
                'message' => $message,
                'type' => 'order',
                'is_read' => 0,
                'date_sent' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $managers->all();
    }

    private function notifyCustomer(int $userId, int $orderId, string $message): void
    {
        $now = now();
        DB::table('notifications')->insert([
            'user_id' => $userId,
            'order_id' => $orderId,
            'message' => $message,
            'type' => 'order',
            'is_read' => 0,
            'date_sent' => $now,
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
