# Change Report: Oct 20 → Oct 31 (backend `vfrb-capstone`)

All figures come from git commands run on 2026-10-31 against local refs. No fetch was run. Helper scripts were kept in the OS temp directory.

## 1. Baseline and branch state

| Item | Value |
|---|---|
| Current branch | `wip/oct21-overhaul` |
| HEAD | `c9c6428` |
| origin/main | `699c644` (matches the expected tip) |
| merge-base | `699c644` |
| Behind / ahead of origin/main | 0 / 6 |
| Tags | `backup-main-2026-10-29` |
| Branches | `main`, `set-order-flow-studio-fixes`, `wip/oct21-overhaul`, `origin/main`, `origin/wip/oct21-overhaul` |
| Staged | `docs/RELEASE_AUDIT_BACKEND.md` (A), `docs/SYSTEM_RECAP.md` (A) |
| Modified (unstaged) | none |
| Untracked | none, apart from this report |

## 2. Commit history

`git log --reverse --format="%h|%ad|%an|%s" --date=short origin/main..HEAD`, with `git show --shortstat` for each commit:

| sha | date | subject | files | +/- | mapped prompt |
|---|---|---|---|---|---|
| c6a3f8c | 2026-10-22 | wip: remove code-built 3D, keep Build your own as 2D | 66 | +3264/-1015 | unmapped |
| 0975fcb | 2026-10-29 | feat(db): RLS on flagged tables, users.assigned_stage, orders.delivery_method | 3 | +95/-0 | unmapped |
| 11be283 | 2026-10-29 | feat(workflow): in-transit, returns, pickup, auto production start, overdue flags, PO and count safeguards | 16 | +1102/-552 | unmapped |
| c56a695 | 2026-10-29 | feat(access): station administrators, request access, upload rules, customer payload hardening, demo seeder | 23 | +1437/-383 | unmapped |
| edeabda | 2026-10-30 | fix(security): token expiry, uniform reset reply, CORS localhost gate | 5 | +119/-13 | unmapped |
| c9c6428 | 2026-10-30 | perf(api): hot-path indexes, one error shape, API docs | 8 | +526/-17 | unmapped |

What each commit does:
- **c6a3f8c**: a large WIP commit. Its subject mentions only 3D removal, but at 66 files it holds most of the overhaul.
- **0975fcb**: adds migrations for RLS, `assigned_stage` and `delivery_method`.
- **11be283**: changes workflow code (delivery, production stages, purchase orders, physical counts).
- **c56a695**: adds station scoping, request-access, upload rules, the customer payload changes and the demo seeder.
- **edeabda**: token expiration, the password-reset reply and the CORS localhost gate.
- **c9c6428**: index migrations, a single error response shape, and `docs/API.md`.

## 3. File delta (`origin/main..HEAD`)

`git diff --name-status` / `--shortstat`: **90 files: 38 Added, 50 Modified, 2 Deleted, 0 Renamed; 6421 insertions, 1858 deletions.**

**Added (38):**
`.mcp.json`, `boost.json`,
`app/Console/Commands/VfrbPurgeTransactions.php`,
`app/Http/Controllers/Api/ClientMaterialController.php`, `app/Http/Controllers/Api/CustomerContactController.php`, `app/Http/Controllers/Api/UploadRulesController.php`,
`app/Http/Middleware/StationScope.php`,
`app/Models/CustomerContact.php`, `app/Models/PersonalAccessToken.php`,
`app/Services/ClientMaterialService.php`, `app/Services/MaterialShortageService.php`, `app/Support/UploadRules.php`,
8 migrations (listed in §5), `database/seeders/DemoDataSeeder.php`,
`docs/API.md`, `docs/SECURITY_AUDIT.md`, `resources/views/pdf/spec-sheet.blade.php`, `scripts/api-docs.php`,
13 tests (listed in §8).

**Deleted (2)**, sizes from `git ls-tree -l origin/main`, 6084 bytes in total:

| file | bytes |
|---|---|
| app/Http/Controllers/Api/ProductionIncidentController.php | 5182 |
| app/Models/ProductionIncident.php | 902 |

**Top modified files** (`git diff --numstat --diff-filter=M`, sorted by total lines changed; all 40):

| total | + | - | file |
|---|---|---|---|
| 601 | 341 | 260 | app/Services/ProductionStageService.php |
| 557 | 371 | 186 | app/Http/Controllers/Api/OrderController.php |
| 291 | 152 | 139 | app/Http/Controllers/Api/PurchaseOrderController.php |
| 283 | 282 | 1 | composer.lock |
| 274 | 203 | 71 | app/Http/Controllers/Api/DeliveryController.php |
| 268 | 139 | 129 | routes/api.php |
| 243 | 192 | 51 | app/Console/Commands/VfrbDailyDigest.php |
| 243 | 142 | 101 | app/Http/Controllers/Api/UserController.php |
| 234 | 75 | 159 | app/Http/Controllers/Api/AuthController.php |
| 157 | 87 | 70 | app/Http/Controllers/Api/PhysicalCountController.php |
| 144 | 97 | 47 | app/Models/Order.php |
| 142 | 87 | 55 | app/Services/BillingService.php |
| 135 | 96 | 39 | tests/Feature/SecurityGateTest.php |
| 131 | 75 | 56 | app/Http/Controllers/Api/ProductionController.php |
| 126 | 113 | 13 | tests/Feature/OrderWorkflowIntegrityTest.php |
| 116 | 112 | 4 | tests/Feature/OrderLifecycleTest.php |
| 111 | 64 | 47 | app/Http/Controllers/Api/BillingController.php |
| 100 | 80 | 20 | app/Http/Controllers/Api/InventoryController.php |
| 100 | 62 | 38 | app/Http/Controllers/Api/DesignController.php |
| 93 | 68 | 25 | app/Http/Controllers/Api/AdminDashboardController.php |
| 64 | 35 | 29 | app/Http/Controllers/Api/SalesTransactionController.php |
| 59 | 44 | 15 | app/Http/Middleware/RoleMiddleware.php |
| 51 | 30 | 21 | bootstrap/app.php |
| 45 | 42 | 3 | app/Http/Controllers/Api/MessageController.php |
| 36 | 25 | 11 | tests/Feature/AiRecommendationTest.php |
| 35 | 15 | 20 | app/Http/Controllers/Api/AdminReportController.php |
| 32 | 17 | 15 | app/Models/User.php |
| 31 | 15 | 16 | resources/views/pdf/invoice.blade.php |
| 19 | 16 | 3 | tests/Feature/AiRecommendationFeedbackTest.php |
| 17 | 14 | 3 | tests/Feature/AiDesignEditsTest.php |
| 16 | 16 | 0 | tests/Feature/OwnerReadOnlyTest.php |
| 16 | 10 | 6 | app/Models/AuditLog.php |
| 16 | 10 | 6 | app/Http/Controllers/Api/AIController.php |
| 15 | 9 | 6 | resources/views/billing/invoice.blade.php |
| 11 | 5 | 6 | config/cors.php |
| 11 | 2 | 9 | app/Http/Middleware/OwnerReadOnly.php |
| 8 | 7 | 1 | app/Http/Controllers/Api/OutputLogController.php |
| 8 | 1 | 7 | app/Http/Controllers/Api/ActivityLogController.php |
| 7 | 5 | 2 | resources/views/billing/receipt.blade.php |
| 5 | 5 | 0 | config/database.php |

**By top-level directory** (`git diff --numstat` aggregated with awk):

| dir | files | + | - |
|---|---|---|---|
| (root) | 4 | 308 | 1 |
| app | 43 | 3104 | 1602 |
| bootstrap | 1 | 30 | 21 |
| config | 3 | 12 | 7 |
| database | 9 | 609 | 0 |
| docs | 2 | 402 | 0 |
| resources | 4 | 135 | 24 |
| routes | 1 | 139 | 129 |
| scripts | 1 | 99 | 0 |
| tests | 22 | 1583 | 74 |

## 4. Uncommitted work (UNCOMMITTED)

| scope | A | M | D | insertions | deletions |
|---|---|---|---|---|---|
| `git diff HEAD` (UNCOMMITTED) | 2 | 0 | 0 | 378 | 0 |
| `git diff --cached` (UNCOMMITTED, staged) | 2 | 0 | 0 | 378 | 0 |

The staged files are `docs/RELEASE_AUDIT_BACKEND.md` and `docs/SYSTEM_RECAP.md`. They were already staged before this task started. No untracked files exist besides this report.

## 5. Schema and migrations

All 8 migrations are new (A). Names below are taken from the migration code.

| migration | table | columns / indexes |
|---|---|---|
| 2026_10_20_000001_create_customer_contacts_table | `customer_contacts` (create) | `name`, `organization_name`, `email`, `mobile`, `address`, `user_id`, `created_by`, `linked_at`; index `mobile` |
| 2026_10_21_000001_add_client_material_ledger | `client_material_ledger` (create); `materials` (alter) | `order_id`, `material_id`, `type` (enum), `qty`, `note`, `recorded_by`; index on `order_id`…; `materials.ownership` (enum) |
| 2026_10_22_000001_add_design_3d_snapshot_to_orders | `orders` | `client_design_3d_snapshot_file` |
| 2026_10_22_000002_enable_rls_on_flagged_tables | `invoices`, `payment_receipts`, `audit_logs`, `customer_contacts`, `client_material_ledger`, `recommendation_guidance` | `ENABLE ROW LEVEL SECURITY`; skipped unless the driver is `pgsql` |
| 2026_10_25_000001_add_assigned_stage_to_users | `users` | `assigned_stage` |
| 2026_10_29_000001_add_delivery_method_to_orders | `orders` | `delivery_method` |
| 2026_10_30_000001_add_hot_path_indexes | `orders`, `order_messages`, `notifications`, `order_production_tracking` | `idx_orders_created` (`created_at`), `idx_om_read_order` (`is_read`,`order_id`), `idx_notif_user_sent` (`user_id`,`date_sent`), `idx_opt_order_stage` (`order_id`,`stage`) |
| 2026_10_30_000002_add_tracking_updated_index | `order_production_tracking` | index `updated_at` |

Dependencies: `composer.json` adds `"laravel/boost": "^2.10"` (composer.lock +282/-1). `package.json` is unchanged.

Live database state for these migrations: **UNVERIFIED**. The memory note saying they were applied on 2026-10-31 is not git evidence.

## 6. API and routes

Comparison of `routes/api.php` at both commits, whitespace-normalized. `routes/web.php` has no changes.

**Removed**

| method | path | controller | middleware |
|---|---|---|---|
| POST | /register | AuthController@register | throttle:5,1 |
| GET | /admin/dashboard/staff | AdminDashboardController@staffIndex | admin group |
| GET | /admin/production-incidents | ProductionIncidentController@index | jobfn:production |
| POST | /admin/production-incidents | ProductionIncidentController@store | jobfn:production |
| PATCH | /admin/production-incidents/{id}/acknowledge | ProductionIncidentController@acknowledge | jobfn:production |
| PATCH | /admin/production-incidents/{id}/resolve | ProductionIncidentController@resolve | jobfn:production |

**Added**

| method | path | controller | middleware |
|---|---|---|---|
| POST | /request-access | CustomerContactController@requestAccess | throttle:5,1 |
| GET | (customer) /orders/{id}/spec-sheet | OrderController@customerSpecSheet | customer group |
| GET | (customer) /messages/unread-count | MessageController@customerUnreadCount | customer group |
| GET | (customer) /upload-rules | UploadRulesController (invokable) | customer group |
| GET | /admin/customer-contacts | CustomerContactController@index | admin group |
| POST | /admin/customer-contacts | CustomerContactController@store | admin group |
| GET | /admin/orders/{id}/client-materials | ClientMaterialController@show | admin group |
| POST | /admin/orders/{id}/client-materials | ClientMaterialController@store | jobfn:inventory |
| GET | /admin/inventory/client-stock | InventoryController@clientStock | admin group |
| GET | /admin/messages/unread-count | MessageController@adminUnreadCount | admin group |
| GET | /admin/orders/{id}/spec-sheet | OrderController@adminSpecSheet | admin group |

**Changed**

| route | before | after |
|---|---|---|
| POST /ai/describe-design | `throttle:30,1` | `['auth:sanctum','throttle:30,1']` |
| POST /ai/design-edits | `throttle:20,1` | `['auth:sanctum','throttle:20,1']` |
| admin group | `role:staff,manager,owner`, `owner.readonly`, `auth.throttle:staff,120` | `role:manager,owner,staff`, `owner.readonly`, **`station`**, `auth.throttle:staff,120` |
| POST /admin/login | withoutMiddleware `auth:sanctum`, `role:…` | also drops `owner.readonly` and `station` |

**Code facts** from the diffs of `bootstrap/app.php`, `config/*.php` and `app/Http/*`:
- `config/sanctum.php`: `expiration` changes from `null` to `(int) env('SANCTUM_EXPIRATION', 60 * 24 * 7)`.
- `config/cors.php`: allowed origins are now `[env('FRONTEND_URL')]`. The localhost origins are added only when `env('CORS_ALLOW_LOCALHOST', env('APP_ENV') === 'local')` is true.
- `bootstrap/app.php`: registers the middleware alias `'station' => StationScope::class`. The 5xx message masking now depends on `! config('app.debug')` instead of `environment('production')`. The JSON error body changes from `{message, status}` to `{message}`.
- `StationScope.php` (new): defines `ALLOWED`, `STAGE_WRITES` (`POST api/admin/output-logs`, `POST api/admin/orders/{orderId}/qc` → `qc`, `POST api/admin/orders/{id}/defects` → `sewing`) and `MONEY_KEYS` (keys stripped from JSON).
- Controller/middleware diff stat: 29 files, +1889/-1161.

## 7. Workflow changes

Constants added under `app/` (from `git diff -U0` lines matching `const`):

| constant | value / note |
|---|---|
| `AUTO_INVOICE_DUE_DAYS` | `30` |
| `TYPES` | `['received', 'issued', 'wastage', 'returned']` |
| `CLIENT_TYPES` | `['individual', 'corporate', 'school', 'medical', 'government', 'organization']` |
| `REFERENCE_MIMES` / `REFERENCE_MAX_KB` | `['jpg','jpeg','png','pdf']` / `5120` |
| `CACHE_KEYS` | `['dashboard_stats', 'admin_reports_index', 'mrp_alerts']` |
| `ALLOWED`, `STAGE_WRITES`, `MONEY_KEYS` | StationScope (§6) |
| `PURGE`, `EPS` | `PURGE` in VfrbPurgeTransactions; `EPS = 0.0001` |

Methods added: `startProduction(int $orderId)`, `autoIssueOnDelivery(int $orderId, int $userId)`, `autoInvoice(int $orderId)`, `overdueAlerts()`.

String literals on added lines in `app/` (`grep -c`): `'delivery_method'` 10, `'assigned_stage'` 9, `'returned'` 8, `'pickup'` 6, `'in_transit'` 4, `'overdue_flags'` 1, `'overdue_materials'` 1.

Removed: the `ProductionIncident` model, its controller and its 4 routes (GET, POST, 2x PATCH).
`/production-incidents` routes removed in c6a3f8c; commit message does not mention it; intent UNVERIFIED.

## 8. Tests

Test methods were counted with `grep -cE "public function test|#\[Test\]|(it|test)\("` at origin/main and at HEAD. 13 files were added and 9 modified; none were removed.

| file | status | base | head |
|---|---|---|---|
| AdminRolesTest | A | 0 | 4 |
| AuthHardeningTest | A | 0 | 4 |
| AutoBillingTest | A | 0 | 6 |
| CustomerContactTest | A | 0 | 6 |
| DailyDigestOverdueTest | A | 0 | 3 |
| DailyDigestRfqTest | A | 0 | 2 |
| DesignLockTest | A | 0 | 10 |
| MaterialShortageTest | A | 0 | 3 |
| MessageUnreadTest | A | 0 | 3 |
| PurchaseOrderWorkflowTest | A | 0 | 4 |
| RequestAccessTest | A | 0 | 4 |
| StationScopeTest | A | 0 | 7 |
| UploadRulesTest | A | 0 | 2 |
| AiDesignEditsTest | M | 4 | 5 |
| AiRecommendationFeedbackTest | M | 7 | 7 |
| AiRecommendationTest | M | 27 | 27 |
| AuditLogTest | M | 4 | 4 |
| OrderLifecycleTest | M | 10 | 16 |
| OrderStatusLockTest | M | 10 | 10 |
| OrderWorkflowIntegrityTest | M | 11 | 17 |
| OwnerReadOnlyTest | M | 2 | 3 |
| SecurityGateTest | M | 20 | 23 |

Test results: **UNVERIFIED** (no tests were run).

## 9. Cross-repo contract

Generated by a temp script from frontend `e248c9e..4d8b158` (`src` client calls) and backend `699c644..c9c6428` (`git diff -w` of `routes/api.php`). Paths normalized: `/api`, `admin/`, `customer/` prefixes and query strings dropped, params shown as `{}`.

| endpoint (normalized) | frontend client diff | backend routes diff | path in backend api.php at HEAD |
|---|---|---|---|
| `/ai/analytics-summary` | removed | - | yes |
| `/billing-profiles/{}` | added/removed | - | yes |
| `/customer-contacts` | added | added | yes |
| `/dashboard` | added/removed | - | yes |
| `/dashboard/staff` | removed | removed | no |
| `/delivery` | removed | - | yes |
| `/delivery/{}` | removed | - | yes |
| `/delivery/{}/status` | added | - | yes |
| `/designs/{}` | added | - | yes |
| `/designs/{}/copy` | added | - | yes |
| `/inventory/client-stock` | added | added | yes |
| `/inventory/export` | added/removed | - | yes |
| `/inventory/stock-{}` | added/removed | - | no |
| `/materials` | added/removed | - | yes |
| `/materials/{}` | added/removed | - | yes |
| `/materials/{}/adjust` | added | - | yes |
| `/messages` | added | - | yes |
| `/messages/{}` | removed | - | yes |
| `/messages/unread-count` | - | added | yes |
| `/orders` | added/removed | - | yes |
| `/orders/{}` | added/removed | - | yes |
| `/orders/{}/{}` | added | - | no |
| `/orders/{}/ai-recommendation` | removed | - | yes |
| `/orders/{}/billing` | removed | - | yes |
| `/orders/{}/client-materials` | added | added | yes |
| `/orders/{}/invoice-pdf` | removed | - | yes |
| `/orders/{}/production` | removed | - | yes |
| `/orders/{}/spec-sheet` | - | added | yes |
| `/orders/production-summaries` | added/removed | - | yes |
| `/output-logs` | added/removed | - | yes |
| `/physical-counts` | added | - | yes |
| `/production-incidents` | - | removed | no |
| `/production-incidents/{}/acknowledge` | - | removed | no |
| `/production-incidents/{}/resolve` | - | removed | no |
| `/profile` | removed | - | yes |
| `/register` | removed | removed | no |
| `/request-access` | - | added | yes |
| `/rfq` | removed | - | yes |
| `/upload-rules` | added | added | yes |
| `/users` | added/removed | - | yes |
| `/users/{}` | added/removed | - | yes |

"no" in the last column means no literal match in backend `routes/api.php` at HEAD; dynamic paths (`{}` segments built in JS) may still resolve. Runtime contract: UNVERIFIED.

## 10. Prompt/milestone mapping

`git log --format=%B origin/main..HEAD | grep -E "\bM[0-9]{1,2}\b|Account [1-5]"` returned no matches.

| prompt | commits | files |
|---|---|---|
| (none mapped) | — | — |

Unmapped: c6a3f8c, 0975fcb, 11be283, c56a695, edeabda, c9c6428 (all 6).

## 11. UNVERIFIED register

| item | check that would prove it |
|---|---|
| The 8 migrations are applied on live Supabase | `php artisan migrate:status` against prod (read-only, with approval) |
| RLS is actually enabled on the 6 tables | `select relname, relrowsecurity from pg_class where relname in (...)` on prod |
| Backend deployment matches HEAD or origin/main | Hosting dashboard / deployed commit sha |
| The test suite passes | `php artisan test` on local `vfrb_test` |
| Runtime behaviour (token expiry, CORS gate, station scoping, auto invoice, overdue flags) | Feature tests plus manual API calls on staging |
| Any commit ↔ M1–M16 / Account 1–5 link | The original prompt texts (not in the repo) |
| origin/main is current with the remote | `git fetch` (not allowed in this task) |
| Frontend calls to the added or removed endpoints | Frontend report §6 / grep of the client |
