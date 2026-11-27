<?php

use App\Http\Controllers\Api\AIController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\BillingProfileController;
use App\Http\Controllers\Api\CustomerFabricPreferenceController;
use App\Http\Controllers\Api\ReferenceCheckController;
use App\Http\Controllers\Api\ReferenceFileController;
use App\Http\Controllers\Api\DeliveryController;
use App\Http\Controllers\Api\DesignController;
use App\Http\Controllers\Api\FeedbackController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\NotificationPreferenceController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\OrderDraftController;
use App\Http\Controllers\Api\ShippingAddressController;
use App\Http\Controllers\Api\UploadRulesController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

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
    Route::get('/orders', [OrderController::class, 'customerIndex']);
    Route::post('/orders', [OrderController::class, 'customerStore']);
    Route::get('/orders/{id}', [OrderController::class, 'customerShow']);
    Route::get('/orders/{id}/spec-sheet', [OrderController::class, 'customerSpecSheet']);
    // Proposal replies: accept confirms the order; decline needs a reason; message goes to the order thread.
    Route::post('/orders/{id}/accept', [OrderController::class, 'customerAcceptProposal']);
    Route::post('/orders/{id}/decline', [OrderController::class, 'customerDeclineProposal']);
    Route::post('/orders/{id}/message', [OrderController::class, 'customerProposalMessage']);

    // ── AI Material Recommendation ───────────────────────────────
    // AIMaterials.jsx: POST /api/customer/ai/recommend-materials  { order_id: selId }
    // (frontend sends body param, not route param — aliased here)
    // FIX: controller method is recommendMaterials() — recommendByBody() never existed.
    Route::post('/ai/recommend-materials', [AIController::class, 'recommendMaterials'])->middleware('throttle:10,1'); // Gemini cost cap, per user
    Route::post('/ai/validate-reference', ReferenceCheckController::class)->middleware('throttle:10,1');
    Route::get('/reference-validations', [ReferenceCheckController::class, 'mine']);
    Route::get('/reference-validations/{id}/file', [ReferenceFileController::class, 'mine'])->whereNumber('id');

    // ── Accept AI recommendation (order-level, bulk — used by AIMaterials.jsx) ──
    // AIMaterials.jsx: POST /api/customer/orders/{id}/accept-materials  { notes }
    // FIX: controller method is acceptRecommendation() — acceptByOrder() never existed.
    Route::post('/orders/{id}/accept-materials', [AIController::class, 'acceptRecommendation']);

    // ── Reject AI recommendation (order-level — used by OrderDetail.jsx) ────────
    // NEW — no backend counterpart existed before.
    Route::post('/orders/{id}/reject-materials', [AIController::class, 'rejectRecommendation']);

    // ── Customer chooses their own materials instead of the AI ──────────────
    // NEW — read-only catalog (no stock/cost exposed to customers) + manual pick
    Route::get('/materials-catalog', [AIController::class, 'customerMaterialsCatalog']);
    Route::post('/orders/{id}/select-materials', [AIController::class, 'customerSelectMaterials']);

    // ── Get recommendation summary for an order (OrderDetail.jsx) ──────────────
    // FIX: old route pointed at getRecommendations() which never existed and was
    // never called by the frontend anyway. OrderDetail.jsx actually calls
    // GET /api/customer/orders/{id}/ai-recommendation — routed here now.
    Route::get('/orders/{id}/ai-recommendation', [AIController::class, 'showForOrder']);

    // ── Billing: own orders only; no amounts on screen, amount-free PDFs (?format=pdf or ?format=html) ──
    Route::get('/orders/{id}/billing', [BillingController::class, 'customerOrderBilling']);
    Route::get('/invoices/{id}/document', [BillingController::class, 'customerInvoiceDocument']);
    Route::get('/receipts/{id}/document', [BillingController::class, 'customerReceiptDocument']);

    // ── Design archive/catalog (InspoGallery.jsx) ────────────────
    // GET /api/customer/designs — starter templates + this customer's
    // own auto-archived past designs (see DesignController).
    Route::get('/designs', [DesignController::class, 'customerIndex']);
    Route::put('/designs/{id}', [DesignController::class, 'customerUpdate']);
    Route::post('/designs/{id}/copy', [DesignController::class, 'customerCopy']);
    Route::delete('/designs/{id}', [DesignController::class, 'customerDestroy']);
    Route::get('/designs/showcase', [DesignController::class, 'customerShowcaseIndex']);
    Route::post('/designs/{id}/submit-showcase', [DesignController::class, 'customerSubmitShowcase']);
    Route::post('/designs/{id}/withdraw-showcase', [DesignController::class, 'customerWithdrawShowcase']);

    // ── Messages ─────────────────────────────────────────────────
    // Messages.jsx:    GET  /api/customer/messages         (thread list)
    //                  POST /api/customer/messages         (send message)
    //                  GET  /api/customer/messages/{id}    (thread for order)
    // OrderDetail.jsx: GET  /api/customer/messages/{id}    (same — order messages)
    //                  POST /api/customer/messages         (quick reply from detail page)
    Route::get('/messages', [MessageController::class, 'customerIndex']);
    Route::get('/messages/unread-count', [MessageController::class, 'customerUnreadCount']);
    Route::post('/messages', [MessageController::class, 'customerStore']);
    Route::get('/messages/{id}', [MessageController::class, 'customerShow']);

    // ── Notifications ────────────────────────────────────────────
    // CustomerLayout.jsx: GET /api/customer/notifications/summary (bell badge)
    // CustomerDashboard.jsx: GET /api/customer/notifications + PATCH /{id}/read
    Route::get('/notifications/summary', [NotificationController::class, 'customerSummary']);
    Route::get('/notifications', [NotificationController::class, 'customerIndex']);
    Route::patch('/notifications/{id}/read', [NotificationController::class, 'customerMarkRead']);
    Route::post('/notifications/read-all', [NotificationController::class, 'customerMarkAllRead']);

    // ── Delivery (read-only for customer) ────────────────────────
    // OrderDetail.jsx may show delivery status — customer can only view
    Route::get('/delivery/{orderId}', [DeliveryController::class, 'customerShow']);

    // ── Profile ──────────────────────────────────────────────────
    // Profile.jsx: GET /api/customer/profile
    //              PUT /api/customer/profile
    //              PUT /api/customer/profile/password
    Route::get('/profile', [UserController::class, 'customerProfile']);
    Route::put('/profile', [UserController::class, 'customerUpdateProfile']);
    Route::put('/profile/password', [UserController::class, 'customerUpdatePassword']);

    // ── Shipping addresses / Billing profiles / Fabric preferences (Sept 16
    // 2026) — customer-owned account records, not yet wired into OrderWizard
    // or invoice generation (see each migration's comment for scope).
    Route::get('/shipping-addresses', [ShippingAddressController::class, 'index']);
    Route::post('/shipping-addresses', [ShippingAddressController::class, 'store']);
    Route::put('/shipping-addresses/{id}', [ShippingAddressController::class, 'update']);
    Route::delete('/shipping-addresses/{id}', [ShippingAddressController::class, 'destroy']);

    Route::get('/billing-profiles', [BillingProfileController::class, 'index']);
    Route::post('/billing-profiles', [BillingProfileController::class, 'store']);
    Route::put('/billing-profiles/{id}', [BillingProfileController::class, 'update']);
    Route::delete('/billing-profiles/{id}', [BillingProfileController::class, 'destroy']);

    // Model + table existed already (one row per user); controller/routes
    // were the only missing piece (Sept 20 2026 audit).
    Route::get('/notification-preferences', [NotificationPreferenceController::class, 'show']);
    Route::put('/notification-preferences', [NotificationPreferenceController::class, 'update']);

    // Picker catalog reuses the existing /materials-catalog endpoint
    // (AIController::customerMaterialsCatalog) — filter client-side to
    // category === 'Fabric'. No duplicate catalog route here.
    Route::get('/fabric-preferences', [CustomerFabricPreferenceController::class, 'index']);
    Route::post('/fabric-preferences', [CustomerFabricPreferenceController::class, 'store']);
    Route::delete('/fabric-preferences/{id}', [CustomerFabricPreferenceController::class, 'destroy']);

    // DesignStudio.jsx: Design draft persistence (Task T)
    //   GET    /api/customer/drafts/latest  → restore latest draft on mount
    //   POST   /api/customer/drafts         → auto-save (debounced 30s) + manual Save
    //   DELETE /api/customer/drafts/latest  → clear draft after Order This
    Route::get('/drafts/latest', [OrderDraftController::class, 'latest']);
    Route::post('/drafts', [OrderDraftController::class, 'store']);
    Route::delete('/drafts/latest', [OrderDraftController::class, 'destroy']);

    // Studio + Order Wizard: accepted reference-file types and size (App\Support\UploadRules).
    Route::get('/upload-rules', UploadRulesController::class);

    // ── Feedback (Aug 27 2026) — any customer can submit a note about the
    // system itself. Deliberately minimal — see migration comment.
    Route::post('/feedback', [FeedbackController::class, 'store']);
});
