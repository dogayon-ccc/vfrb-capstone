<?php
// routes/api.php — VFRB Enterprise
// ============================================================
// VERIFIED against:
//   1. Every axios call in every JSX file (extracted from threejs.zip)
//   2. Every controller method in our 10 verified controllers
//   3. vfrb_db.sql schema (roles: customer, staff, manager)
//
// ROUTE CORRECTIONS vs previous sessions:
//   - /api/admin/notifications uses PATCH /{id}/read (not /notifications/{id}/read)
//   - /api/customer/ai/recommend-materials is a POST with body {order_id}
//     (frontend sends body, not route param — route alias added)
//   - /api/customer/orders/{id}/accept-materials maps to AIController@acceptByOrder
//   - /api/admin/delivery/{id}/delivered is a separate quick-mark endpoint
//   - /api/admin/inventory/stock-{type} maps dynamically to stockIn or stockOut
//   - /api/admin/physical-counts/summary and /sheet are dedicated endpoints
//   - /api/admin/output-logs/{id} summary uses a different param pattern
//   - /api/admin/messages (admin order messaging) needs its own controller ref
//   - /api/admin/notifications in Dashboard calls GET without {id}
//
// THROTTLE RULES (TASK Q — Month 4):
//   Login:    throttle:10,1
//   Register: throttle:5,1
//   AI public: throttle:30,1
//
// ROLE MIDDLEWARE:
//   'role:customer'        — Spatie: customer role only
//   'role:staff,manager'   — Spatie: either staff OR manager
//   'role:manager'         — Spatie: manager only
//   No supplier portal — ever.

use App\Http\Controllers\Api\AIController;
use App\Http\Controllers\Api\AdminDashboardController;
use App\Http\Controllers\Api\AdminReportController;
use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DeliveryController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\OutputLogController;
use App\Http\Controllers\Api\PhysicalCountController;
use App\Http\Controllers\Api\ProductionController;
use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\QCChecklistController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\SalesTransactionController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\AutomationController;
use App\Http\Controllers\Api\OrderDraftController;
use Illuminate\Support\Facades\Route;

// ══════════════════════════════════════════════════════════════
// PUBLIC ROUTES (no auth)
// ══════════════════════════════════════════════════════════════

// ── Customer auth ─────────────────────────────────────────────
Route::post('/login',    [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');

// ── Admin auth (alias so both /api/admin/login and /api/login work) ──
Route::post('/admin/login', [AuthController::class, 'adminLogin'])->middleware('throttle:10,1');

// ── Password reset ────────────────────────────────────────────
Route::post('/password/forgot', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
Route::post('/password/reset',  [AuthController::class, 'resetPassword'])->middleware('throttle:5,1');

// ── AI Design Studio prompt (PUBLIC — customer uses before login) ─
Route::post('/ai/describe-design', [AIController::class, 'describeDesign'])->middleware('throttle:30,1');

// ── Google OAuth ──────────────────────────────────────────────
// Real routes now live in routes/web.php (Aug 23 2026) — GET
// /auth/google/redirect and /auth/google/callback. They're full-page
// browser navigations, not axios calls, so they need the 'web' session
// middleware, not the stateless 'api' group this file uses.

// ══════════════════════════════════════════════════════════════
// AUTHENTICATED — SHARED (any logged-in user)
// ══════════════════════════════════════════════════════════════
// Rate limiting (Aug 23 2026): group-level default via the existing
// ApiThrottle middleware (already used for login/register) rather than
// annotating each route individually — this group covers lightweight,
// frequently-polled endpoints (session check, notification prefs), so
// the limit is generous but no longer absent.
Route::middleware(['auth:sanctum', 'auth.throttle:shared,60'])->group(function () {

    // ── Current user ────────────────────────────────────────────
    // GET /api/user — VerifyEmail.jsx calls this to refresh email_verified_at
    Route::get('/user', [AuthController::class, 'me']);

    // ── Logout ──────────────────────────────────────────────────
    // AdminLayout.jsx + CustomerLayout.jsx both call POST /api/logout
    Route::post('/logout', [AuthController::class, 'logout']);

    // ── Email verification ───────────────────────────────────────
    // VerifyEmail.jsx calls POST /api/email/resend
    Route::post('/email/resend', [AuthController::class, 'resendVerification'])->middleware('throttle:5,1');

    // ── Notification preferences (own row only, any role) ─────────
    // Settings.jsx: GET/PATCH /api/settings/notifications
    Route::get('/settings/notifications',   [SettingsController::class, 'notificationShow']);
    Route::patch('/settings/notifications', [SettingsController::class, 'notificationUpdate']);

});

// ══════════════════════════════════════════════════════════════
// CUSTOMER PORTAL
// ══════════════════════════════════════════════════════════════
// Rate limiting (Aug 23 2026): 60/min — normal browsing + order placement
// traffic for a single customer never approaches this; it exists purely
// to blunt a runaway script or compromised session, not to slow down
// real use.
Route::middleware(['auth:sanctum', 'role:customer', 'auth.throttle:customer,60'])->prefix('customer')->group(function () {

    // ── Dashboard ───────────────────────────────────────────────
    // CustomerDashboard.jsx: GET /api/customer/dashboard
    Route::get('/dashboard', [OrderController::class, 'customerDashboard']);

    // ── Orders ──────────────────────────────────────────────────
    // Orders.jsx:      GET  /api/customer/orders
    // OrderWizard.jsx: POST /api/customer/orders  (multipart/form-data with studio_config)
    // OrderDetail.jsx: GET  /api/customer/orders/{id}
    // AIMaterials.jsx: GET  /api/customer/orders  (to build order selector)
    //                  GET  /api/customer/orders/{id} (load specific order)
    Route::get('/orders',     [OrderController::class, 'customerIndex']);
    Route::post('/orders',    [OrderController::class, 'customerStore']);
    Route::get('/orders/{id}', [OrderController::class, 'customerShow']);

    // ── AI Material Recommendation ───────────────────────────────
    // AIMaterials.jsx: POST /api/customer/ai/recommend-materials  { order_id: selId }
    // (frontend sends body param, not route param — aliased here)
    // FIX: controller method is recommendMaterials() — recommendByBody() never existed.
    Route::post('/ai/recommend-materials', [AIController::class, 'recommendMaterials']);

    // ── Accept AI recommendation (order-level, bulk — used by AIMaterials.jsx) ──
    // AIMaterials.jsx: POST /api/customer/orders/{id}/accept-materials  { notes }
    // FIX: controller method is acceptRecommendation() — acceptByOrder() never existed.
    Route::post('/orders/{id}/accept-materials', [AIController::class, 'acceptRecommendation']);

    // ── Reject AI recommendation (order-level — used by OrderDetail.jsx) ────────
    // NEW — no backend counterpart existed before.
    Route::post('/orders/{id}/reject-materials', [AIController::class, 'rejectRecommendation']);

    // ── Customer chooses their own materials instead of the AI ──────────────
    // NEW — read-only catalog (no stock/cost exposed to customers) + manual pick
    Route::get('/materials-catalog',        [AIController::class, 'customerMaterialsCatalog']);
    Route::post('/orders/{id}/select-materials', [AIController::class, 'customerSelectMaterials']);

    // ── Get recommendation summary for an order (OrderDetail.jsx) ──────────────
    // FIX: old route pointed at getRecommendations() which never existed and was
    // never called by the frontend anyway. OrderDetail.jsx actually calls
    // GET /api/customer/orders/{id}/ai-recommendation — routed here now.
    Route::get('/orders/{id}/ai-recommendation', [AIController::class, 'showForOrder']);

    // ── Messages ─────────────────────────────────────────────────
    // Messages.jsx:    GET  /api/customer/messages         (thread list)
    //                  POST /api/customer/messages         (send message)
    //                  GET  /api/customer/messages/{id}    (thread for order)
    // OrderDetail.jsx: GET  /api/customer/messages/{id}    (same — order messages)
    //                  POST /api/customer/messages         (quick reply from detail page)
    Route::get('/messages',     [MessageController::class, 'customerIndex']);
    Route::post('/messages',    [MessageController::class, 'customerStore']);
    Route::get('/messages/{id}', [MessageController::class, 'customerShow']);

    // ── Notifications ────────────────────────────────────────────
    // CustomerLayout.jsx: GET /api/customer/notifications/summary (bell badge)
    // CustomerDashboard.jsx: GET /api/customer/notifications + PATCH /{id}/read
    Route::get('/notifications/summary', [NotificationController::class, 'customerSummary']);
    Route::get('/notifications',         [NotificationController::class, 'customerIndex']);
    Route::patch('/notifications/{id}/read', [NotificationController::class, 'customerMarkRead']);
    Route::post('/notifications/read-all',   [NotificationController::class, 'customerMarkAllRead']);

    // ── Delivery (read-only for customer) ────────────────────────
    // OrderDetail.jsx may show delivery status — customer can only view
    Route::get('/delivery/{orderId}', [DeliveryController::class, 'customerShow']);

    // ── Profile ──────────────────────────────────────────────────
    // Profile.jsx: GET /api/customer/profile
    //              PUT /api/customer/profile
    //              PUT /api/customer/profile/password
    Route::get('/profile',            [UserController::class, 'customerProfile']);
    Route::put('/profile',            [UserController::class, 'customerUpdateProfile']);
    Route::put('/profile/password',   [UserController::class, 'customerUpdatePassword']);

    // DesignStudio.jsx: Design draft persistence (Task T)
    //   GET    /api/customer/drafts/latest  → restore latest draft on mount
    //   POST   /api/customer/drafts         → auto-save (debounced 30s) + manual Save
    //   DELETE /api/customer/drafts/latest  → clear draft after Order This
    Route::get('/drafts/latest',    [OrderDraftController::class, 'latest']);
    Route::post('/drafts',          [OrderDraftController::class, 'store']);
    Route::delete('/drafts/latest', [OrderDraftController::class, 'destroy']);

});

// ══════════════════════════════════════════════════════════════
// ADMIN PORTAL — STAFF + MANAGER (operational routes)
// ══════════════════════════════════════════════════════════════
// Rate limiting (Aug 23 2026): 120/min — highest of the four tiers, since
// AdminLayout.jsx polls notifications and dashboard widgets fairly
// actively during a normal work shift. Still low enough to stop a
// scripted scrape or a runaway retry loop.
Route::middleware(['auth:sanctum', 'role:staff,manager', 'auth.throttle:staff,120'])->prefix('admin')->group(function () {

    // ── Dashboard ────────────────────────────────────────────────
    // Dashboard.jsx: GET /api/admin/dashboard
    //                GET /api/admin/notifications (bell dropdown — reads same table)
    Route::get('/dashboard', [AdminDashboardController::class, 'index']);

    // ── Notifications ────────────────────────────────────────────
    // Dashboard.jsx:  GET  /api/admin/notifications?per_page=20
    //                 POST /api/admin/notifications/read-all
    //                 PATCH /api/admin/notifications/{id}/read
    // NOTE: read-all must come BEFORE /{id}/read to avoid Laravel treating
    //       "read-all" as an {id} parameter.
    Route::get('/notifications',              [NotificationController::class, 'adminIndex']);
    Route::post('/notifications/read-all',    [NotificationController::class, 'adminMarkAllRead']);
    Route::patch('/notifications/{id}/read',  [NotificationController::class, 'adminMarkRead']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'adminUnreadCount']);

    // ── Auth ─────────────────────────────────────────────────────
    Route::post('/login', [AuthController::class, 'adminLogin'])->withoutMiddleware(['auth:sanctum', 'role:staff,manager'])->middleware('throttle:10,1');

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
    Route::get('/orders',      [OrderController::class, 'adminIndex']);
    Route::get('/orders/{id}', [OrderController::class, 'adminShow']);
    // Invoice.jsx: "Download PDF" button -> GET /api/admin/orders/{id}/invoice-pdf
    Route::get('/orders/{id}/invoice-pdf', [OrderController::class, 'downloadInvoicePdf']);
    Route::patch('/orders/{id}', [OrderController::class, 'adminUpdate']);

    // ── Production Stage Advance ──────────────────────────────────
    // ProductionTracking.jsx: GET  /api/admin/orders/{id}/production  → stages()
    //                         POST /api/admin/orders/{id}/advance     → advance() (manager override)
    // DailyOutputLog.jsx:     POST /api/admin/orders/{id}/log-output  → logProgress()
    //                         POST /api/admin/orders/{id}/log-progress → logProgress() (alias)
    Route::get('/orders/{id}/production',    [ProductionController::class, 'stages']);
    Route::post('/orders/{id}/log-output',   [ProductionController::class, 'logProgress']);
    Route::post('/orders/{id}/log-progress', [ProductionController::class, 'logProgress']);

    // ── QC Checklist ─────────────────────────────────────────────
    // QCChecklist.jsx: GET  /api/admin/orders/{orderId}/qc
    //                  POST /api/admin/orders/{orderId}/qc
    Route::get('/orders/{orderId}/qc',  [QCChecklistController::class, 'show']);
    Route::post('/orders/{orderId}/qc', [QCChecklistController::class, 'store']);

    // ── Daily Output Logs ─────────────────────────────────────────
    // DailyOutputLog.jsx: GET  /api/admin/output-logs?date=...
    //                     GET  /api/admin/output-logs/summary/{orderId}
    //                     POST /api/admin/output-logs  ← DailyOutputLog.jsx submits here
    // summary/{orderId} must come BEFORE /{id} to avoid param collision
    // POST must also come before /{id} for Laravel route resolution
    Route::get('/output-logs/summary/{orderId}', [OutputLogController::class, 'summaryByOrder']);
    Route::post('/output-logs',                  [OutputLogController::class, 'store']);
    Route::get('/output-logs',                   [OutputLogController::class, 'index']);
    Route::get('/output-logs/{id}',              [OutputLogController::class, 'show']);
    Route::delete('/output-logs/{id}',           [OutputLogController::class, 'destroy']);

    // ── Inventory ─────────────────────────────────────────────────
    // Inventory.jsx: GET /api/admin/inventory
    //                GET /api/admin/inventory/logs
    //                POST /api/admin/inventory/stock-in  (type = stock_in)
    //                POST /api/admin/inventory/stock-out (type = stock_out)
    // Frontend uses: axios.post(`/api/admin/inventory/stock-${type}`)
    // where type is 'in' or 'out' — routes must match exactly
    Route::get('/inventory',      [InventoryController::class, 'index']);
    Route::get('/inventory/logs', [InventoryController::class, 'allLogs']); // FIX: was 'logs' → 500
    Route::post('/inventory/stock-in',  [InventoryController::class, 'stockIn']);
    Route::post('/inventory/stock-out', [InventoryController::class, 'stockOut']);

    // ── Materials ─────────────────────────────────────────────────
    // Materials.jsx:    GET  /api/admin/materials
    // PhysicalCount.jsx:GET  /api/admin/materials  (for dropdown)
    // PurchaseOrders.jsx:GET /api/admin/materials  (for RFQ dropdown)
    // Inventory.jsx:    PUT  /api/admin/materials/{id}
    Route::get('/materials',       [InventoryController::class, 'index']);
    Route::post('/materials',      [InventoryController::class, 'store']);
    Route::put('/materials/{id}',  [InventoryController::class, 'update']);
    Route::delete('/materials/{id}', [InventoryController::class, 'destroy']);
    Route::get('/materials/{id}',  [InventoryController::class, 'show']);
    Route::post('/materials/{id}/stock-in',  [InventoryController::class, 'stockIn']);
    Route::post('/materials/{id}/stock-out', [InventoryController::class, 'stockOut']);
    Route::post('/materials/{id}/adjust',    [InventoryController::class, 'adjust']);
    Route::get('/materials/{id}/logs',       [InventoryController::class, 'logs']);

    // ── Material usage rates (deterministic BOM engine — staff-configured) ──
    // Feeds AIController::computeDeterministicQty(). Never touched by the AI.
    Route::get('/material-rates',        [InventoryController::class, 'ratesIndex']);
    Route::post('/material-rates',       [InventoryController::class, 'ratesUpsert']);
    Route::delete('/material-rates/{id}', [InventoryController::class, 'ratesDestroy']);
    // garment-types route moved above orders/{id} — see that registration

    // ── Physical Count ────────────────────────────────────────────
    // PhysicalCount.jsx: GET  /api/admin/physical-counts
    //                    GET  /api/admin/physical-counts/summary
    //                    GET  /api/admin/physical-counts/sheet
    //                    POST /api/admin/physical-counts
    //                    PATCH /api/admin/physical-counts/{id}/reconcile (manager only — but staff triggers, manager approves)
    // summary and sheet must come BEFORE /{id} to avoid collision
    Route::get('/physical-counts/summary', [PhysicalCountController::class, 'summary']);
    Route::get('/physical-counts/sheet',   [PhysicalCountController::class, 'sheet']);
    Route::get('/physical-counts',         [PhysicalCountController::class, 'index']);
    Route::post('/physical-counts',        [PhysicalCountController::class, 'store']);

    // ── Messages (order-based thread) ────────────────────────────
    // Messages.jsx:    GET  /api/admin/messages        (all threads)
    //                  GET  /api/admin/messages/{id}   (thread for order)
    //                  POST /api/admin/messages        (send)
    Route::get('/messages',      [MessageController::class, 'adminIndex']);
    Route::get('/messages/{id}', [MessageController::class, 'adminShow']);
    Route::post('/messages',     [MessageController::class, 'adminStore']);

    // ── Delivery ──────────────────────────────────────────────────
    // DeliveryTracking.jsx: GET   /api/admin/delivery
    //                       PATCH /api/admin/delivery/{id}/status
    //                       PATCH /api/admin/delivery/{id}/delivered (quick-mark)
    Route::get('/delivery',                       [DeliveryController::class, 'index']);
    Route::get('/delivery/{id}',                  [DeliveryController::class, 'show']);
    Route::post('/delivery',                      [DeliveryController::class, 'store']);
    Route::patch('/delivery/{id}/status',         [DeliveryController::class, 'updateStatus']);
    Route::patch('/delivery/{id}/delivered',      [DeliveryController::class, 'markDelivered']);

    // ── Purchase Orders ───────────────────────────────────────────
    // PurchaseOrders.jsx: GET   /api/admin/purchase-orders
    //                     POST  /api/admin/purchase-orders  (create PO)
    //                     PATCH /api/admin/purchase-orders/{id}/receive
    //                     PATCH /api/admin/purchase-orders/{id}/confirm-color
    Route::get('/purchase-orders',                           [PurchaseOrderController::class, 'index']);
    Route::post('/purchase-orders',                          [PurchaseOrderController::class, 'store']);
    Route::get('/purchase-orders/{id}',                      [PurchaseOrderController::class, 'show']);
    Route::patch('/purchase-orders/{id}/receive',            [PurchaseOrderController::class, 'receive']);

    // ── RFQ ───────────────────────────────────────────────────────
    // PurchaseOrders.jsx: GET  /api/admin/rfq
    //                     POST /api/admin/rfq           (staff creates RFQ)
    //                     POST /api/admin/rfq/{id}/respond    (staff logs supplier reply)
    //                     PATCH /api/admin/rfq/{id}/close
    //                     PATCH /api/admin/rfq/{id}/convert-po (manager converts to PO)
    Route::get('/rfq',                          [PurchaseOrderController::class, 'rfqIndex']);
    Route::post('/rfq',                         [PurchaseOrderController::class, 'rfqStore']);
    Route::post('/rfq/{id}/respond',            [PurchaseOrderController::class, 'rfqRespond']);
    Route::patch('/rfq/{id}/close',             [PurchaseOrderController::class, 'rfqClose']);

    // ── Suppliers (master data — no portal, read/write only) ─────
    // PurchaseOrders.jsx: GET /api/admin/suppliers
    //                     POST /api/admin/suppliers
    // Suppliers.jsx:      GET /api/admin/suppliers
    //                     PUT /api/admin/suppliers/{id}
    Route::get('/suppliers',        [PurchaseOrderController::class, 'suppliersIndex']);
    Route::post('/suppliers',       [PurchaseOrderController::class, 'suppliersStore']);
    Route::put('/suppliers/{id}',   [PurchaseOrderController::class, 'suppliersUpdate']);

    // ── Sales Transactions ────────────────────────────────────────
    // SalesTransactions.jsx: GET  /api/admin/transactions
    //                        POST /api/admin/transactions
    Route::get('/transactions',  [SalesTransactionController::class, 'index']);
    Route::post('/transactions', [SalesTransactionController::class, 'store']);
    Route::get('/transactions/{id}', [SalesTransactionController::class, 'show']);

    // ── Reports (TASK P) ─────────────────────────────────────────
    // Reports.jsx: GET /api/admin/reports
    //              GET /api/admin/reports/sales
    // analytics-summary is manager-only but staffs can still view reports index
    Route::get('/reports',        [AdminReportController::class, 'index']);
    Route::get('/reports/sales',  [AdminReportController::class, 'salesSummary']);

    // ── System Settings — company info (view only here; edit is manager-only below) ──
    // Settings.jsx: GET /api/admin/settings/company
    Route::get('/settings/company', [SettingsController::class, 'companyShow']);

});

// ══════════════════════════════════════════════════════════════
// ADMIN PORTAL — MANAGER ONLY (approve + financial routes)
// ══════════════════════════════════════════════════════════════
// Rate limiting (Aug 23 2026): 60/min — lower tier, matches customer/shared.
// This group covers Reports, Invoice, Suppliers, Users, Activity Log,
// and Settings edits — deliberate, lower-frequency actions, not something
// a manager does dozens of times a minute in normal use.
Route::middleware(['auth:sanctum', 'role:manager', 'auth.throttle:manager,60'])->prefix('admin')->group(function () {

    // ── Activity Log (Aug 22 2026) — genuinely manager-only, unlike the
    // Reports index above which staff CAN reach on the backend. This reads
    // across every staff/manager action table, so it stays fully gated. ──
    // ActivityLog.jsx: GET /api/admin/activity-log
    //                  GET /api/admin/activity-log/action-types
    Route::get('/activity-log',              [ActivityLogController::class, 'index']);
    Route::get('/activity-log/action-types', [ActivityLogController::class, 'actionTypes']);

    // ── Stage advance (manager override — bypasses qty check, still gates QC) ─
    // ProductionTracking.jsx advance button
    Route::post('/orders/{id}/advance',  [ProductionController::class, 'advance']);
    Route::patch('/orders/{id}/confirm', [ProductionController::class, 'confirm']); // pending → confirmed
    Route::get('/orders/{id}/material-check', [ProductionController::class, 'materialCheck']); // pre-confirm feasibility warning

    // ── Physical count reconciliation (manager approves) ─────────
    // PhysicalCount.jsx: PATCH /api/admin/physical-counts/{id}/reconcile
    Route::patch('/physical-counts/{id}/reconcile', [PhysicalCountController::class, 'reconcile']);

    // ── Color confirmation (manager unblocks cutting) ─────────────
    // PurchaseOrders.jsx: PATCH /api/admin/purchase-orders/{id}/confirm-color
    Route::patch('/purchase-orders/{id}/confirm-color', [PurchaseOrderController::class, 'confirmColor']);

    // ── RFQ convert to PO (manager only) ─────────────────────────
    // PurchaseOrders.jsx: PATCH /api/admin/rfq/{id}/convert-po
    Route::patch('/rfq/{id}/convert-po', [PurchaseOrderController::class, 'rfqConvertToPO']);

    // ── User management (manager creates all staff accounts) ─────
    // UserManagement.jsx: GET    /api/admin/users
    //                     POST   /api/admin/users  (create staff/manager)
    //                     POST   /api/admin/users/create  (alias — Register.jsx uses this)
    //                     PATCH  /api/admin/users/{id}/toggle  (activate/deactivate)
    // create must come BEFORE /{id} to avoid collision
    Route::get('/users',                     [UserController::class, 'adminIndex']);
    Route::post('/users/create',             [UserController::class, 'adminCreate']);
    Route::post('/users',                    [UserController::class, 'adminCreate']);
    Route::patch('/users/{id}/toggle',       [UserController::class, 'adminToggle']);

    // ── Automation — manual "Run Now" trigger (manager only) ──────
    // Same command the scheduler calls (routes/console.php) — manual and
    // scheduled runs share one source of truth, can't drift apart.
    Route::post('/automation/run-digest', [AutomationController::class, 'run']);

    // ── AI Analytics Summary (manager dashboard) ─────────────────
    // Dashboard.jsx + Reports.jsx: GET /api/admin/ai/analytics-summary
    // FIX: controller method is analyticsSummary() — adminSummary() never existed.
    Route::get('/ai/analytics-summary', [AIController::class, 'analyticsSummary']);

    // ── Prescriptive MRP alerts (TASK P) ─────────────────────────
    // Reports.jsx: GET /api/admin/reports/prescriptive-alerts
    // FIX: prescriptiveAlerts() never existed on AdminReportController — added.
    // (Reports.jsx's Alerts tab actually reads prescriptive_alerts from the main
    //  GET /api/admin/reports response, so this dedicated route was unused/dead —
    //  it's now wired to a real method rather than left to 500 if ever called.)
    Route::get('/reports/prescriptive-alerts', [AdminReportController::class, 'prescriptiveAlerts']);

    // ── Output log deletion (manager corrects mis-entries) ───────
    Route::delete('/output-logs/{id}', [OutputLogController::class, 'destroy']);

    // ── System Settings — company info edit (manager only; staff has view-only above) ──
    // Settings.jsx: PATCH /api/admin/settings/company
    //               POST  /api/admin/settings/company/logo
    Route::patch('/settings/company',      [SettingsController::class, 'companyUpdate']);
    Route::post('/settings/company/logo',  [SettingsController::class, 'companyLogoUpload'])->middleware('auth.throttle:logo-upload,10');

});