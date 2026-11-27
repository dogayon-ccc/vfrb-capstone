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
//   'role:customer'        — Spatie: customer only (DB role name is 'customer' — UI/routes say
//                              "client", the DB role identifier does not; don't rename this string)
//   'role:staff,manager'   — Spatie: either staff OR manager
//   'role:manager'         — Spatie: manager only
//   No supplier portal — ever.

// Route groups live in routes/api/*.php. Order matters: a route is matched by the first definition that fits.
require __DIR__.'/api/public.php';
require __DIR__.'/api/shared.php';
require __DIR__.'/api/customer.php';
require __DIR__.'/api/staff.php';
require __DIR__.'/api/manager.php';
