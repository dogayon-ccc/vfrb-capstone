<?php

use App\Http\Controllers\Api\AdminDashboardController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\ClientMaterialController;
use App\Http\Controllers\Api\CustomerContactController;
use App\Http\Controllers\Api\DeliveryController;
use App\Http\Controllers\Api\FeedbackController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\OutputLogController;
use App\Http\Controllers\Api\PhysicalCountController;
use App\Http\Controllers\Api\ProductionController;
use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\QCChecklistController;
use App\Http\Controllers\Api\RecommendationGuidanceController;
use App\Http\Controllers\Api\SalesTransactionController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

// ══════════════════════════════════════════════════════════════
// ADMIN PORTAL — MANAGER + OWNER + STATION (operational routes; StationScope limits staff)
// ══════════════════════════════════════════════════════════════
// Rate limiting (Aug 23 2026): 120/min — highest of the four tiers, since
// AdminLayout.jsx polls notifications and dashboard widgets fairly
// actively during a normal work shift. Still low enough to stop a
// scripted scrape or a runaway retry loop.
Route::middleware(['auth:sanctum', 'role:manager,owner,staff', 'owner.readonly', 'station', 'auth.throttle:staff,120'])->prefix('admin')->group(function () {

    // AI recommendation feedback (Oct 13 2026): staff accept/reject per recommended line.
    Route::patch('/material-recommendations/{recId}/feedback', [RecommendationGuidanceController::class, 'feedback'])->whereNumber('recId');

    // ── Dashboard ────────────────────────────────────────────────
    // Dashboard.jsx: GET /api/admin/dashboard
    //                GET /api/admin/notifications (bell dropdown — reads same table)
    Route::get('/dashboard', [AdminDashboardController::class, 'index']);

    // ── Feedback (Aug 27 2026) — any staff/manager can also submit; only
    // managers can view the list (see role:manager group below).
    Route::post('/feedback', [FeedbackController::class, 'store']);

    // ── Notifications ────────────────────────────────────────────
    // Dashboard.jsx:  GET  /api/admin/notifications?per_page=20
    //                 POST /api/admin/notifications/read-all
    //                 PATCH /api/admin/notifications/{id}/read
    // NOTE: read-all must come BEFORE /{id}/read to avoid Laravel treating
    //       "read-all" as an {id} parameter.
    Route::get('/notifications', [NotificationController::class, 'adminIndex']);
    Route::post('/notifications/read-all', [NotificationController::class, 'adminMarkAllRead']);
    Route::patch('/notifications/{id}/read', [NotificationController::class, 'adminMarkRead']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'adminUnreadCount']);

    // ── Auth ─────────────────────────────────────────────────────
    // The excluded role string must match the group's exactly, or RoleMiddleware answers every login with 401.
    Route::post('/login', [AuthController::class, 'adminLogin'])->withoutMiddleware(['auth:sanctum', 'role:manager,owner,staff', 'owner.readonly', 'station'])->middleware('throttle:10,1');

    // ── Orders ───────────────────────────────────────────────────
    // Orders.jsx:           GET /api/admin/orders
    // SalesTransactions.jsx:GET /api/admin/orders  (for order selector)
    // QCChecklist.jsx:      GET /api/admin/orders?status=qc
    // ProductionTracking.jsx:GET /api/admin/orders?per_page=50
    // DailyOutputLog.jsx:   GET /api/admin/orders?status=pattern,...
    // Orders.jsx detail:    GET /api/admin/orders/{id}
    // ROUTE ORDER MATTERS: garment-types must be registered BEFORE {id}
    // below, or Laravel's top-down route matching swallows "garment-types"
    // into the {id} wildcard (binding $id = 'garment-types', a string)
    // before it ever reaches this dedicated route — confirmed via the
    // real TypeError in storage/logs/laravel.log: adminShow(): Argument
    // #1 ($id) must be of type int, string given.
    Route::get('/orders/garment-types', [OrderController::class, 'garmentTypes']);
    // Orders.jsx column + Dashboard.jsx "Orders in production" card (also before {id}).
    Route::get('/orders/production-summaries', [OutputLogController::class, 'productionSummaries']);
    Route::get('/orders', [OrderController::class, 'adminIndex']);

    // ── Client master data (read-only) — CustomersMaster.jsx: GET /api/admin/customers
    // Customers live in `users` (role 'customer'); staff + manager may view, nobody edits from here.
    Route::get('/customers', [UserController::class, 'adminCustomers']);

    // ── Walk-in customer contacts: the manager records them. No account is created.
    Route::get('/customer-contacts', [CustomerContactController::class, 'index']);
    Route::post('/customer-contacts', [CustomerContactController::class, 'store']);
    Route::get('/orders/{id}', [OrderController::class, 'adminShow']);
    // Invoice.jsx "Download PDF" button moved to the manager-only group
    // below (Sept 15 2026, QA account) — Invoice is manager-exclusive per
    // the locked business rules; this endpoint was reachable by any staff
    // bearer token here, a real RBAC gap.
    Route::patch('/orders/{id}', [OrderController::class, 'adminUpdate']);

    // Client-supplied materials per order (receive / wastage / return; Cutting issues automatically).
    Route::get('/orders/{id}/client-materials', [ClientMaterialController::class, 'show']);
    Route::post('/orders/{id}/client-materials', [ClientMaterialController::class, 'store'])->middleware('jobfn:inventory');

    // ── Production Stage Advance ──────────────────────────────────
    // ProductionTracking.jsx: GET  /api/admin/orders/{id}/production  → stages()
    //                         POST /api/admin/orders/{id}/advance     → advance() (manager override)
    // Actual output logging (DailyOutputLog.jsx) posts to
    // POST /api/admin/output-logs → OutputLogController::store() — see
    // that route below. This section previously also registered
    // /log-output and /log-progress → ProductionController::logProgress(),
    // but that method was never called by any frontend page (the comment
    // claiming DailyOutputLog.jsx used it was stale/aspirational) and, after
    // the Aug 28 2026 consolidation, delegated 100% of its logic to
    // ProductionStageService::logOutput() with no unique code of its own —
    // confirmed zero functional loss, removed as dead code (Aug 28 2026).
    Route::get('/orders/{id}/production', [ProductionController::class, 'stages']);
    Route::get('/orders/{id}/production-summary', [OutputLogController::class, 'productionSummary']);
    Route::post('/orders/{id}/defects', [OutputLogController::class, 'reportDefect'])->middleware('jobfn:production');

    // ── QC Checklist ─────────────────────────────────────────────
    // DailyOutputLog.jsx (inline at the QC stage): GET/POST /api/admin/orders/{orderId}/qc
    // QCChecklist.jsx (read-only history):          GET /api/admin/qc-checklists
    Route::get('/orders/{orderId}/qc', [QCChecklistController::class, 'show'])->middleware('jobfn:production');
    Route::get('/qc-checklists', [QCChecklistController::class, 'index'])->middleware('jobfn:production');
    Route::post('/orders/{orderId}/qc', [QCChecklistController::class, 'store'])->middleware('jobfn:production');

    // ── Daily Output Logs ─────────────────────────────────────────
    // DailyOutputLog.jsx: GET  /api/admin/output-logs?date=...
    //                     GET  /api/admin/output-logs/summary/{orderId}
    //                     POST /api/admin/output-logs  ← DailyOutputLog.jsx submits here
    // summary/{orderId} must come BEFORE /{id} to avoid param collision
    // POST must also come before /{id} for Laravel route resolution
    Route::get('/output-logs/summary/{orderId}', [OutputLogController::class, 'summaryByOrder'])->middleware('jobfn:production');
    Route::post('/output-logs', [OutputLogController::class, 'store'])->middleware('jobfn:production');
    Route::get('/output-logs', [OutputLogController::class, 'index'])->middleware('jobfn:production');
    Route::get('/output-logs/{id}', [OutputLogController::class, 'show'])->middleware('jobfn:production');

    // ── Inventory ─────────────────────────────────────────────────
    // Inventory.jsx: GET /api/admin/inventory
    //                GET /api/admin/inventory/logs
    //                POST /api/admin/inventory/stock-in  (type = stock_in)
    //                POST /api/admin/inventory/stock-out (type = stock_out)
    // Frontend uses: axios.post(`/api/admin/inventory/stock-${type}`)
    // where type is 'in' or 'out' — routes must match exactly
    Route::get('/inventory', [InventoryController::class, 'index']);
    Route::get('/inventory/logs', [InventoryController::class, 'allLogs']); // FIX: was 'logs' → 500
    Route::get('/inventory/client-stock', [InventoryController::class, 'clientStock']); // client-supplied material per open order
    Route::get('/inventory/export', [InventoryController::class, 'export']); // ?format=csv|pdf, stock only (no costs)
    Route::post('/inventory/stock-in', [InventoryController::class, 'stockIn'])->middleware('jobfn:inventory');
    Route::post('/inventory/stock-out', [InventoryController::class, 'stockOut'])->middleware('jobfn:inventory');

    // ── Materials ─────────────────────────────────────────────────
    // Materials.jsx:    GET  /api/admin/materials
    // PhysicalCount.jsx:GET  /api/admin/materials  (for dropdown)
    // PurchaseOrders.jsx:GET /api/admin/materials  (for RFQ dropdown)
    // Inventory.jsx:    PUT  /api/admin/materials/{id}
    Route::get('/materials', [InventoryController::class, 'index'])->middleware('jobfn:inventory');
    Route::post('/materials', [InventoryController::class, 'store'])->middleware('jobfn:inventory');
    Route::put('/materials/{id}', [InventoryController::class, 'update'])->middleware('jobfn:inventory');
    Route::delete('/materials/{id}', [InventoryController::class, 'destroy'])->middleware('jobfn:inventory');
    Route::get('/materials/{id}', [InventoryController::class, 'show'])->middleware('jobfn:inventory');
    Route::post('/materials/{id}/stock-in', [InventoryController::class, 'stockIn'])->middleware('jobfn:inventory');
    Route::post('/materials/{id}/stock-out', [InventoryController::class, 'stockOut'])->middleware('jobfn:inventory');
    Route::post('/materials/{id}/adjust', [InventoryController::class, 'adjust'])->middleware('jobfn:inventory');
    Route::get('/materials/{id}/logs', [InventoryController::class, 'logs'])->middleware('jobfn:inventory');

    // Material usage-rate routes removed (Aug 29 2026, this pass) — the
    // three controller methods they pointed to (InventoryController::
    // ratesIndex/ratesUpsert/ratesDestroy) were already deleted Aug 28 2026
    // alongside MaterialRates.jsx (see InventoryController.php's own
    // comment at that spot). These three routes were left behind pointing
    // at nonexistent methods — any request to them would have thrown a
    // fatal BadMethodCallException. Genuinely dead now, not orphaned.
    // garment-types route moved above orders/{id} — see that registration

    // ── Physical Count ────────────────────────────────────────────
    // PhysicalCount.jsx: GET  /api/admin/physical-counts
    //                    GET  /api/admin/physical-counts/summary
    //                    GET  /api/admin/physical-counts/sheet
    //                    POST /api/admin/physical-counts
    //                    PATCH /api/admin/physical-counts/{id}/reconcile (manager only — but staff triggers, manager approves)
    // summary and sheet must come BEFORE /{id} to avoid collision
    Route::get('/physical-counts/summary', [PhysicalCountController::class, 'summary'])->middleware('jobfn:inventory');
    Route::get('/physical-counts/sheet', [PhysicalCountController::class, 'sheet'])->middleware('jobfn:inventory');
    Route::get('/physical-counts', [PhysicalCountController::class, 'index'])->middleware('jobfn:inventory');
    Route::post('/physical-counts', [PhysicalCountController::class, 'store'])->middleware('jobfn:inventory');

    // ── Messages (order-based thread) ────────────────────────────
    // Messages.jsx:    GET  /api/admin/messages        (all threads)
    //                  GET  /api/admin/messages/{id}   (thread for order)
    //                  POST /api/admin/messages        (send)
    Route::get('/messages', [MessageController::class, 'adminIndex']);
    Route::get('/messages/unread-count', [MessageController::class, 'adminUnreadCount']);
    Route::get('/messages/{id}', [MessageController::class, 'adminShow']);
    Route::post('/messages', [MessageController::class, 'adminStore']);

    // ── Delivery ──────────────────────────────────────────────────
    // DeliveryTracking.jsx: GET   /api/admin/delivery
    //                       PATCH /api/admin/delivery/{id}/status
    //                       PATCH /api/admin/delivery/{id}/delivered (quick-mark)
    Route::get('/delivery', [DeliveryController::class, 'index']);
    Route::get('/delivery/{id}', [DeliveryController::class, 'show']);
    Route::post('/delivery', [DeliveryController::class, 'store']);
    Route::patch('/delivery/{id}/status', [DeliveryController::class, 'updateStatus']);
    Route::patch('/delivery/{id}/delivered', [DeliveryController::class, 'markDelivered']);

    // ── Purchase Orders ───────────────────────────────────────────
    // PurchaseOrders.jsx: GET   /api/admin/purchase-orders
    //                     POST  /api/admin/purchase-orders  (create PO)
    //                     PATCH /api/admin/purchase-orders/{id}/receive
    //                     PATCH /api/admin/purchase-orders/{id}/confirm-color
    Route::get('/purchase-orders', [PurchaseOrderController::class, 'index']);
    Route::post('/purchase-orders', [PurchaseOrderController::class, 'store']);
    Route::get('/purchase-orders/{id}', [PurchaseOrderController::class, 'show']);
    Route::patch('/purchase-orders/{id}/receive', [PurchaseOrderController::class, 'receive'])->middleware('jobfn:inventory'); // credits stock, same guard as stock-in

    // ── RFQ ───────────────────────────────────────────────────────
    // PurchaseOrders.jsx: GET  /api/admin/rfq
    //                     POST /api/admin/rfq           (staff creates RFQ)
    //                     POST /api/admin/rfq/{id}/respond    (staff logs supplier reply)
    //                     PATCH /api/admin/rfq/{id}/close
    //                     PATCH /api/admin/rfq/{id}/convert-po (manager converts to PO)
    Route::get('/rfq', [PurchaseOrderController::class, 'rfqIndex']);
    Route::post('/rfq', [PurchaseOrderController::class, 'rfqStore']);
    Route::post('/rfq/{id}/respond', [PurchaseOrderController::class, 'rfqRespond']);
    Route::patch('/rfq/{id}/close', [PurchaseOrderController::class, 'rfqClose']);

    // ── Suppliers — READ ONLY here (staff+manager) ────────────────
    // FIX (Aug 28 2026 — role gap): Suppliers.jsx is manager-exclusive
    // in the frontend nav (App.jsx wraps its route in <RequireManager>),
    // but the write routes (POST/PUT) were sitting in this staff+manager
    // group, so any staff bearer token could create/edit supplier master
    // data directly via the API, bypassing the nav restriction entirely.
    // GET stays here deliberately: PurchaseOrders.jsx (NOT manager-gated —
    // staff creates RFQs per the locked business rule "Staff: New RFQ →
    // select supplier → select material") reads this same endpoint to
    // populate its supplier dropdown in LogResponseModal. Confirmed via
    // direct grep that no staff-facing JSX ever POSTs or PUTs to
    // /suppliers — only Suppliers.jsx does, and it's already
    // manager-gated on the frontend. Moving GET here too would have
    // broken the real, locked RFQ workflow — moving only the writes
    // closes the actual gap without breaking anything.
    // POST/PUT moved to the manager-only group below.
    Route::get('/suppliers', [PurchaseOrderController::class, 'suppliersIndex']);

    // ── Sales Transactions ────────────────────────────────────────
    // SalesTransactions.jsx: GET  /api/admin/transactions
    //                        POST /api/admin/transactions
    Route::get('/transactions', [SalesTransactionController::class, 'index']);
    Route::post('/transactions', [SalesTransactionController::class, 'store']);
    Route::get('/transactions/{id}', [SalesTransactionController::class, 'show']);
    Route::get('/transactions/{id}/receipt-pdf', [SalesTransactionController::class, 'downloadReceiptPdf']);

    // ── Billing documents (Oct 14 2026): read-only for staff; issue/mark-paid/void are manager-only below ──
    Route::get('/orders/{id}/billing', [BillingController::class, 'adminOrderBilling']);
    Route::get('/invoices/{id}/document', [BillingController::class, 'adminInvoiceDocument']);
    Route::get('/receipts/{id}/document', [BillingController::class, 'adminReceiptDocument']);

    // ── Reports (TASK P) moved to the manager-only group below (Sept 15
    // 2026, QA account) — Reports is manager-exclusive per the locked
    // business rules; both routes were reachable by staff here, a real
    // RBAC gap (the old comment even said so: "staffs can still view
    // reports index").

    // ── System Settings — company info (view only here; edit is manager-only below) ──
    // Settings.jsx: GET /api/admin/settings/company
    Route::get('/settings/company', [SettingsController::class, 'companyShow']);

    // Own password (Settings.jsx Password panel): same rules and current-password check as the customer route.
    Route::put('/profile/password', [UserController::class, 'adminUpdatePassword']);

});
