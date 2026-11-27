<?php

use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\AdminReportController;
use App\Http\Controllers\Api\AIController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AutomationController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\ReferenceFileController;
use App\Http\Controllers\Api\ReferenceReviewController;
use App\Http\Controllers\Api\SetPairController;
use App\Http\Controllers\Api\DesignController;
use App\Http\Controllers\Api\FeedbackController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\OutputLogController;
use App\Http\Controllers\Api\PhysicalCountController;
use App\Http\Controllers\Api\ProductionController;
use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\RecommendationGuidanceController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

// ══════════════════════════════════════════════════════════════
// ADMIN PORTAL — MANAGER ONLY (approve + financial routes)
// ══════════════════════════════════════════════════════════════
// Rate limiting (Aug 23 2026): 60/min — lower tier, matches customer/shared.
// This group covers Reports, Invoice, Suppliers, Users, Activity Log,
// and Settings edits — deliberate, lower-frequency actions, not something
// a manager does dozens of times a minute in normal use.
Route::middleware(['auth:sanctum', 'role:manager,owner', 'owner.readonly', 'auth.throttle:manager,60'])->prefix('admin')->group(function () {

    // ── Reports + Invoice (Sept 15 2026 role-gap fix, QA account) ──
    // Moved from the staff+manager group above: Reports and Invoice are
    // manager-exclusive per the locked business rules, but a staff bearer
    // token could previously reach all three of these directly.
    // Reports.jsx:  GET /api/admin/reports
    //               GET /api/admin/reports/sales
    // Invoice.jsx:  GET /api/admin/orders/{id}/invoice-pdf ("Download PDF")
    Route::get('/reports', [AdminReportController::class, 'index']);
    Route::get('/reports/sales', [AdminReportController::class, 'salesSummary']);
    Route::get('/orders/{id}/invoice-pdf', [OrderController::class, 'downloadInvoicePdf']);
    Route::get('/orders/{id}/spec-sheet', [OrderController::class, 'adminSpecSheet']);

    // ── Order proposal + down payment (Oct 17 2026): manager sets date, total and down payment ──
    Route::get('/orders/{id}/suggested-date', [OrderController::class, 'suggestedDate']);
    Route::post('/orders/{id}/propose', [OrderController::class, 'propose']);
    Route::post('/orders/{id}/down-payment', [OrderController::class, 'recordDownPayment']);

    // ── Billing actions (Oct 14 2026): amounts come only from orders.agreed_total and recorded payments ──
    Route::post('/orders/{id}/invoice', [BillingController::class, 'issue']);
    Route::patch('/invoices/{id}/mark-paid', [BillingController::class, 'markPaid']);
    Route::patch('/invoices/{id}/void', [BillingController::class, 'void']);

    // ── Design showcase moderation (Sept 18 2026) ──────────────────
    // ── AI recommendation guidance (Oct 13 2026): manager-written prompt grounding ──
    // Fabric spec requirements per garment kind (manager edits, owner read-only via owner.readonly)
    Route::get('/material-spec-requirements', [\App\Http\Controllers\Api\MaterialSpecRequirementController::class, 'index']);
    Route::put('/material-spec-requirements/{kind}', [\App\Http\Controllers\Api\MaterialSpecRequirementController::class, 'replace']);
    Route::get('/recommendation-guidance', [RecommendationGuidanceController::class, 'index']);
    Route::post('/recommendation-guidance', [RecommendationGuidanceController::class, 'store']);
    Route::put('/recommendation-guidance/{id}', [RecommendationGuidanceController::class, 'update'])->whereNumber('id');
    // AI quality (Nov 2 2026): acceptance rates, and what the AI would see for one order. Read-only.
    Route::get('/ai/recommendation-metrics', [RecommendationGuidanceController::class, 'metrics']);
    Route::get('/orders/{id}/ai-context', [RecommendationGuidanceController::class, 'aiContext'])->whereNumber('id');

    Route::get('/designs/showcase-queue', [DesignController::class, 'adminShowcaseQueue']);
    Route::post('/designs/{id}/showcase-approve', [DesignController::class, 'adminShowcaseApprove']);
    Route::post('/designs/{id}/showcase-reject', [DesignController::class, 'adminShowcaseReject']);
    Route::post('/designs/{id}/showcase-remove', [DesignController::class, 'adminShowcaseRemove']);

    // ── Suppliers — WRITE routes (Aug 28 2026 role-gap fix) ───────
    // Moved from the staff+manager group above: Suppliers.jsx is already
    // manager-exclusive in the nav (<RequireManager> wrapper), but a staff
    // bearer token could previously hit these two endpoints directly and
    // create/edit supplier master data. GET stays in the staff+manager
    // group above (PurchaseOrders.jsx's staff-facing RFQ flow needs read
    // access to the supplier list — see comment there).
    Route::post('/suppliers', [PurchaseOrderController::class, 'suppliersStore']);
    Route::put('/suppliers/{id}', [PurchaseOrderController::class, 'suppliersUpdate']);

    // ── Feedback triage (Aug 27 2026) — manager-only, like Reports/Users.
    Route::get('/feedback', [FeedbackController::class, 'index']);
    Route::patch('/feedback/{id}', [FeedbackController::class, 'update']);

    // ── Activity Log (Aug 22 2026) — manager-only, like Reports/Invoice
    // above (Sept 15 2026 fix). Reads across every staff/manager action
    // table, so it stays fully gated. ──
    // ActivityLog.jsx: GET /api/admin/activity-log
    //                  GET /api/admin/activity-log/action-types
    Route::get('/activity-log', [ActivityLogController::class, 'index']);
    Route::get('/activity-log/action-types', [ActivityLogController::class, 'actionTypes']);

    // ── Audit Log (Oct 16 2026) — insert-only audit_logs: logins, logouts,
    // failed logins and key writes. Read-only for manager and owner. ──
    Route::get('/audit-logs', [AuditLogController::class, 'index']);

    // ── Stage advance (manager override — bypasses qty check, still gates QC) ─
    // ProductionTracking.jsx advance button
    Route::post('/orders/{id}/advance', [ProductionController::class, 'advance']);
    Route::patch('/orders/{id}/confirm', [ProductionController::class, 'confirm']); // pending → confirmed
    // materialCheck() route removed Aug 29 2026 — the controller method itself
    // was already deleted as part of the no-formula redesign (see confirm()'s
    // doc comment above it), but this GET route was left pointing at the
    // now-nonexistent method. Confirmed zero live callers before removing:
    // Orders.jsx calls PATCH .../confirm directly and reads its response
    // body, never this GET endpoint.

    // ── Physical count reconciliation (manager approves) ─────────
    // PhysicalCount.jsx: PATCH /api/admin/physical-counts/{id}/reconcile
    Route::patch('/physical-counts/{id}/reconcile', [PhysicalCountController::class, 'reconcile'])->middleware('jobfn:inventory');

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
    Route::get('/users', [UserController::class, 'adminIndex']);
    Route::post('/users/create', [UserController::class, 'adminCreate']);
    Route::post('/users', [UserController::class, 'adminCreate']);
    Route::put('/users/{id}', [UserController::class, 'adminUpdate']);
    Route::put('/users/{id}/password', [UserController::class, 'adminSetPassword'])->middleware('throttle:10,1');
    Route::patch('/users/{id}/toggle', [UserController::class, 'adminToggle']);

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
    Route::patch('/settings/company', [SettingsController::class, 'companyUpdate']);
    Route::post('/settings/company/logo', [SettingsController::class, 'companyLogoUpload'])->middleware('auth.throttle:logo-upload,10');
    // Held uploads: staff name the top and bottom before a needs_review reference can become an order.
    Route::get('/reference-validations', [ReferenceReviewController::class, 'index']);
    Route::get('/reference-validations/{id}/file', [ReferenceFileController::class, 'staff'])->whereNumber('id');
    Route::post('/reference-validations/{id}/decision', [ReferenceReviewController::class, 'decide']);
    // Approved top+bottom combinations: the only pairs that can be accepted or approved as a set.
    Route::get('/set-pairs', [SetPairController::class, 'index']);
    Route::post('/set-pairs', [SetPairController::class, 'store']);
    Route::delete('/set-pairs/{id}', [SetPairController::class, 'destroy']);

});
