# Backend release audit: `wip/oct21-overhaul` vs `main` (2026-10-31)

Read-only audit. Labels: CODE-VERIFIED (code or git output read), BUILD-VERIFIED (not used, nothing was run), UNVERIFIED.
Sources: `git log/diff main...HEAD`, files at HEAD, frontend `threejs/docs/engineering/HANDOFF.md` (modified 2026-10-31), memory note `live-migration-state` (threejs project memory, 2026-10-21).

## 1. Baseline

| Item | Value | Label |
|---|---|---|
| Branch | `wip/oct21-overhaul` | CODE-VERIFIED |
| HEAD | `c9c6428` (2026-10-30) | CODE-VERIFIED |
| main tip (last deploy) | `699c644` 2026-10-20 "Stop tracking CLAUDE.md and add it to .gitignore" | CODE-VERIFIED |
| Commits ahead | 6 | CODE-VERIFIED |
| Fast-forward | YES (`git merge-base --is-ancestor main HEAD`) | CODE-VERIFIED |
| Diff size | 90 files, +6421 / -1858 | CODE-VERIFIED |
| HANDOFF tip | says `c56a695`; HEAD is 2 commits newer (`edeabda`, `c9c6428`) | CODE-VERIFIED |
| Deploy status | "NOT deployed" (HANDOFF §Deploy status) | HANDOFF |

**Uncommitted / untracked (`git status --short`):** none before this report. After it: only `?? docs/RELEASE_AUDIT_BACKEND.md`.

**Links (from HANDOFF only):**
- Backend Vercel preview: `vfrb-capstone-git-wip-oct21-overhaul-dsogayon-8611.vercel.app`. It answers 302 to Vercel SSO because Preview protection is on.
- Frontend preview: `vfrb-frontend-pjjzwyhby...` (deployed by CLI; truncated in HANDOFF).
- Production URLs: not recorded in the sources read. UNVERIFIED.
- The preview uses live DB variables, so preview clicks write real rows (HANDOFF).

## 2. History (grouped)

| Theme | Commits | Summary |
|---|---|---|
| Studio / 3D | `c6a3f8c` 2026-10-22 (wip, 66 files) | Removes code-built 3D and keeps "Build your own" as 2D. Also carries customer_contacts, client-material ledger, 3D snapshot column, spec sheet, AI and billing work, ProductionIncident removal, most new tests |
| DB / security | `0975fcb` 2026-10-29 | RLS on flagged tables, `users.assigned_stage`, `orders.delivery_method` |
| Delivery / orders / inventory | `11be283` 2026-10-29 | In-transit, returns, pickup, auto production start, overdue flags, PO and count safeguards |
| Roles / access / uploads / seeder | `c56a695` 2026-10-29 | Station administrators, request access, upload rules, customer payload hardening, DemoDataSeeder |
| Security | `edeabda` 2026-10-30 | Token expiry, uniform reset reply, CORS localhost gate |
| API / perf / docs | `c9c6428` 2026-10-30 | Hot-path indexes, one error shape, `docs/API.md` |

None of the commits have a message body, so themes come from subjects and file lists (CODE-VERIFIED). Because `c6a3f8c` is a mixed "wip" commit, history is hard to bisect.

## 3. Files

Totals: 38 added, 2 deleted, 50 modified, 0 renamed (`git diff --name-status main...HEAD`, CODE-VERIFIED).

| Area | A | D | M |
|---|---|---|---|
| Controllers (Api) | 3 | 1 | 18 |
| Models | 2 | 1 | 5 (AuditLog, KpiDailySnapshot, Material, Order, User) |
| Services | 2 | 0 | 2 (BillingService, ProductionStageService) |
| Middleware | 1 | 0 | 3 (ApiThrottle, OwnerReadOnly, RoleMiddleware) |
| Console | 1 | 0 | 1 (VfrbDailyDigest) |
| Support | 1 | 0 | 1 (StudioSet) |
| Views | 1 | 0 | 3 (billing x2, pdf x1) |
| Config / bootstrap / providers | 0 | 0 | 5 (cors, database, sanctum, bootstrap/app, AppServiceProvider) |
| Routes | 0 | 0 | 1 (`routes/api.php`) |
| Seeders | 1 | 0 | 0 |
| Tests | 13 | 0 | 9 |
| Migrations | 8 | 0 | 0 |
| Other | 5 (.mcp.json, boost.json, docs x2, scripts x1) | 0 | 2 (composer.json/.lock) |

**Added**
- `app/Console/Commands/VfrbPurgeTransactions.php`: deletes order, sales and transaction history; keeps users, roles and materials (file docblock).
- `app/Http/Controllers/Api/ClientMaterialController.php`: per-order client-material receive/wastage/return.
- `app/Http/Controllers/Api/CustomerContactController.php`: walk-in contacts plus public request-access.
- `app/Http/Controllers/Api/UploadRulesController.php`: serves `UploadRules`.
- `app/Http/Middleware/StationScope.php`: station staff allow-list, own-stage writes, money stripping.
- `app/Models/CustomerContact.php`: model for `customer_contacts`.
- `app/Models/PersonalAccessToken.php`: throttles Sanctum `last_used_at` writes to once per 5 min.
- `app/Services/ClientMaterialService.php`: ledger rules shared by Cutting gate, override, delivery closure.
- `app/Services/MaterialShortageService.php`: order material warnings and stock note.
- `app/Support/UploadRules.php`: reference uploads limited to jpg/jpeg/png/pdf, 5120 KB.
- `database/migrations/*` (8): see §5.
- `database/seeders/DemoDataSeeder.php`: demo dataset for `vfrb_demo` (memory `demo-db`).
- `resources/views/pdf/spec-sheet.blade.php`: order spec sheet PDF.
- `docs/API.md`, `scripts/api-docs.php`: generated API reference and its generator.
- `docs/SECURITY_AUDIT.md`: M14 security audit write-up.
- `.mcp.json`, `boost.json`: Laravel Boost / MCP tooling config (dev tooling, committed).
- `tests/Feature/*` (13): see §7.

**Deleted**
- `app/Http/Controllers/Api/ProductionIncidentController.php` and `app/Models/ProductionIncident.php`: production-incident feature removed. Its 4 routes are gone; the table is not dropped (no migration).

## 4. API (`git diff main -- routes/api.php`, CODE-VERIFIED)

| Method | Path | Role / middleware | Change | Controller@method |
|---|---|---|---|---|
| POST | /api/register | public, throttle 5/1 | REMOVED | AuthController@register |
| POST | /api/request-access | public, throttle:5,1 | ADDED | CustomerContactController@requestAccess |
| POST | /api/ai/describe-design | was public; now auth:sanctum, throttle:30,1 | CHANGED | AIController@describeDesign |
| POST | /api/ai/design-edits | was public; now auth:sanctum, throttle:20,1 | CHANGED | AIController@designEdits |
| GET | /api/…/orders/{id}/spec-sheet | customer group | ADDED | OrderController@customerSpecSheet |
| GET | /api/…/messages/unread-count | customer group | ADDED | MessageController@customerUnreadCount |
| GET | /api/…/upload-rules | customer group | ADDED | UploadRulesController (invokable) |
| * | /api/admin/* (whole group) | was `role:staff,manager,owner`; now `role:manager,owner,staff` + `station` | CHANGED | — |
| POST | /api/admin/login | withoutMiddleware now also drops owner.readonly, station | CHANGED | AuthController@adminLogin |
| GET | /api/admin/dashboard/staff | admin group | REMOVED | AdminDashboardController@staffIndex |
| GET/POST/PATCH ×4 | /api/admin/production-incidents[/{id}/acknowledge, /resolve] | jobfn:production | REMOVED | ProductionIncidentController@* |
| GET | /api/admin/customer-contacts | admin group | ADDED | CustomerContactController@index |
| POST | /api/admin/customer-contacts | admin group | ADDED | CustomerContactController@store |
| GET | /api/admin/orders/{id}/client-materials | admin group | ADDED | ClientMaterialController@show |
| POST | /api/admin/orders/{id}/client-materials | jobfn:inventory | ADDED | ClientMaterialController@store |
| GET | /api/admin/inventory/client-stock | admin group | ADDED | InventoryController@clientStock |
| GET | /api/admin/messages/unread-count | admin group | ADDED | MessageController@adminUnreadCount |
| GET | /api/admin/orders/{id}/spec-sheet | manager group `role:manager,owner`, owner.readonly (`routes/api.php:531,543`) | ADDED | OrderController@adminSpecSheet |

Totals: 11 ADDED, 6 REMOVED, 4 CHANGED. The remaining route-file diff lines are whitespace alignment and the FQCN-to-`use` import cleanup.

**Request / response changes**
- Customer money fields: the customer order payload unsets `agreed_total, discount_rate, discount_pct, down_payment_amount, payment_method, payment_terms, qc_required, qc_passed_at` (`OrderController.php:151`). Billing routes are commented as "no amounts on screen, amount-free PDFs" (`routes/api.php`). CODE-VERIFIED.
- Station staff responses: `StationScope::stripMoney` recursively removes `MONEY_KEYS` (`StationScope.php:53,89`). CODE-VERIFIED.
- Upload rules response: `{reference:{mimes:[jpg,jpeg,png,pdf], max_kb:5120}}` (`UploadRules.php:19`). CODE-VERIFIED.
- Delivery: new `delivery_method` field, `required|in:pickup,vfrb_deliver,courier`; `delivery_status` is `in:preparing,in_transit,delivered,returned` (`DeliveryController.php:95,136`). CODE-VERIFIED.
- Return: sets `delivery_status=returned, delivered_qty=0, actual_delivery_date=null` (`DeliveryController.php:379`). CODE-VERIFIED.
- Order create: `preferred_date` is nullable, ≥ `vfrb.min_promised_days` (default 7), with a "no rush orders" message, and maps to `target_delivery_date` (`OrderController.php:247,261,383`). CODE-VERIFIED.
- Order confirm/production: the response may include `material_warnings` (`OrderController.php:882`). CODE-VERIFIED.
- Error shape: JSON errors are now `{message}` only (`status` key removed); 5xx text is fixed unless `APP_DEBUG` (`bootstrap/app.php`). CODE-VERIFIED.
- Station 403 body: `{message, assigned_stage}` (`StationScope.php:77`). CODE-VERIFIED.
- Unread counts (both roles): `{unread_count, threads:[{order_id, unread_count}]}` (`MessageController.php:303-312`). CODE-VERIFIED.
- Spec sheet: PDF of the order's Studio design; 404 if the order is not found, 422 if it has no Studio design (`OrderController.php:572-591`). The PDF output was not viewed. UNVERIFIED (render).

**Config changes** (CODE-VERIFIED)
- `config/sanctum.php`: `expiration` changes from null to `SANCTUM_EXPIRATION` (default 10080 min = 7 days).
- `config/cors.php`: localhost origins are allowed only if `CORS_ALLOW_LOCALHOST` (default: `APP_ENV=local`); otherwise only `FRONTEND_URL`.
- `config/database.php`: pgsql options `DB_PERSISTENT`, `DB_EMULATE_PREPARES` (both off by default).
- `composer.json`: `laravel/boost ^2.10` added to require-dev.

## 5. Schema

| File | Table | Column(s) | Type | MySQL / PG safe | Creates table (RLS?) | Applied on live |
|---|---|---|---|---|---|---|
| 2026_10_20_000001_create_customer_contacts_table | customer_contacts | name, email (unique), mobile (idx), address, organization_name, user_id FK, linked_at, created_by FK | additive | yes / yes (CODE-VERIFIED) | yes; RLS added by 2026_10_22_000002 | YES, batch 45 (live `migrate:status` run by the user 2026-10-31) |
| 2026_10_21_000001_add_client_material_ledger | materials, client_material_ledger | materials.ownership; ledger order_id, material_id, type, qty, note, recorded_by | additive (down drops) | yes / yes | yes; in RLS list | YES, batch 46 (HANDOFF, memory) |
| 2026_10_22_000001_add_design_3d_snapshot_to_orders | orders | client_design_3d_snapshot_file | additive | yes / yes | no | YES, batch 47 (live `migrate:status` run by the user 2026-10-31) |
| 2026_10_22_000002_enable_rls_on_flagged_tables | invoices, payment_receipts, audit_logs, customer_contacts, client_material_ledger, recommendation_guidance | RLS on; audit function search_path | additive; pgsql-only (no-op on MySQL) | yes / yes | — | YES, batch 48 (HANDOFF) |
| 2026_10_25_000001_add_assigned_stage_to_users | users | assigned_stage varchar(20) null | additive | yes / yes | no | YES, batch 49 (HANDOFF) |
| 2026_10_29_000001_add_delivery_method_to_orders | orders | delivery_method varchar(20) default vfrb_deliver | additive | yes / yes | no | YES, batch 50 (HANDOFF) |
| 2026_10_30_000001_add_hot_path_indexes | orders, order_messages, notifications, order_production_tracking | 4 indexes, guarded by `hasIndex` | additive | yes / yes | no | YES, applied 2026-10-31 with `--path` batch 51 (`migrate:status`) |
| 2026_10_30_000002_add_tracking_updated_index | order_production_tracking | idx_opt_updated(updated_at) | additive | yes / yes | no | YES, applied 2026-10-31 with `--path` batch 52 (`migrate:status`) |

None of these migrations is destructive on `up()`. The forbidden `2026_10_12_000001` is not in this diff; on live it is already Ran, batch 38 (memory).

**A live deploy still needs:** no migrations. All 8 are on live; the 2 index migrations were applied 2026-10-31 with `--path` (both DONE).

## 6. Behaviour

| Area | What changed | Where | Label |
|---|---|---|---|
| Role model | Customer; manager; staff = Administrator (Station) with `assigned_stage`; owner read-only via `owner.readonly`. Only an Administrator (Manager) can create or change admin/owner accounts | `routes/api.php`, `UserController.php:173,242`, `OwnerReadOnly.php` | CODE-VERIFIED |
| StationScope | Route allow-list `ALLOWED`, own-stage production writes `STAGE_WRITES` (403 otherwise), money keys stripped from JSON | `StationScope.php:14,46,53,70-98` | CODE-VERIFIED |
| Order lifecycle | Lifecycle and status-lock code changed (557-line diff); full status list not re-read | `OrderController.php` | UNVERIFIED (details) |
| Proposal / acceptance | Audit actions `order_proposal_sent/declined`; customer accept route unchanged | `AuditLog.php:26`, `routes/api.php` | CODE-VERIFIED |
| Down payment, auto production start | Recording the down payment calls `startProduction`; BillingService also starts it after payment | `OrderController.php:1000-1012`, `BillingService.php:151`, `ProductionStageService.php:681` | CODE-VERIFIED |
| Subcontract rule | Direct/bulk: 80% down at confirmation. Subcontract: paid in full after delivery. Order type is locked once the down payment is received | `OrderController.php:62-65,798` | CODE-VERIFIED |
| Delivery | preparing → in_transit → delivered / returned; method pickup / vfrb_deliver / courier. Delivered requires production finished, an address, and client-material closure | `DeliveryController.php:95,136,305-313,379` | CODE-VERIFIED |
| Auto invoice / receipts | Invoice auto-issued on delivery (`autoIssueOnDelivery`) | `DeliveryController.php:276-295` | CODE-VERIFIED |
| Overdue flags | Daily digest flags `order_overdue` / `invoice_overdue` to managers, once each | `VfrbDailyDigest.php:263-280` | CODE-VERIFIED |
| Client material ledger and gates | Receive / wastage / return; Cutting gate and auto issue; manager override; delivery closure blocker | `ClientMaterialService.php:2-5`, `DeliveryController.php:313` | CODE-VERIFIED |
| Physical count reconcile | VFRB stock only (client material → 422); applies the difference, not the counted value; double reconcile → 409; weekly Monday due | `PhysicalCountController.php` (diff) | CODE-VERIFIED |
| PO receive lock | Re-checked under a row lock, so a double submit cannot credit stock twice | `PurchaseOrderController.php` (diff) | CODE-VERIFIED |
| AI scope | Client-owned stock never suggested (`ownership='vfrb'`); result tagged `source` ai / cache / catalog_default; materials locked → 409; Studio AI routes need login | `AIController.php:46-130`, `routes/api.php` | CODE-VERIFIED |
| Upload rules | jpg/jpeg/png/pdf ≤5 MB, served to Studio and Wizard | `UploadRules.php`, `UploadRulesController.php` | CODE-VERIFIED |
| Request access | Creates a contact and notifies managers; never creates a user; register route removed | `routes/api.php`, `RequestAccessTest` | CODE-VERIFIED |
| Throttles | login 10/1, request-access 5/1, reset 5/1, AI 30/1 and 20/1, admin `auth.throttle:staff,120`; ApiThrottle changed (not read) | `routes/api.php` | CODE-VERIFIED / ApiThrottle UNVERIFIED |
| Auth hardening | Deactivated admin/station accounts cannot sign in; identical forgot-password reply; 7-day token expiry | `AuthController.php` (diff), `config/sanctum.php` | CODE-VERIFIED |
| Audit actions | New: proposal sent/declined, down payment received, client_material_* ×3, customer_contact_created, access_requested; unknown actions rejected | `AuditLog.php:23-33` | CODE-VERIFIED |
| RFQ automation | Daily digest auto-creates open RFQs for the low-stock threshold gap | `VfrbDailyDigest.php:47,119` | CODE-VERIFIED |

## 7. Tests (not run)

Last known count: **223 tests pass on vfrb_test (MySQL)** (HANDOFF §Verified, at tip `c56a695`). Not re-run here, and the 2 newer commits are not covered by that count (UNVERIFIED). Postgres behaviour is UNVERIFIED (HANDOFF).

| File | A/M | Covers (from test names) |
|---|---|---|
| AdminRolesTest | A | Owner cannot write in manager areas; role creation limits |
| AuthHardeningTest | A | Sanctum expiry, identical forgot-password reply, no localhost CORS outside local |
| AutoBillingTest | A | Auto invoice on delivery / packed with delivery |
| CustomerContactTest | A | Manager creates a contact without a user account |
| DailyDigestOverdueTest | A | Late orders and overdue invoices flagged once |
| DailyDigestRfqTest | A | Auto RFQ for the threshold gap; skips materials without one |
| DesignLockTest | A | Design locked once linked to an order (B4) |
| MaterialShortageTest | A | Order shortage warnings |
| MessageUnreadTest | A | Unread counts per role |
| PurchaseOrderWorkflowTest | A | PO receive, double-receive guard |
| RequestAccessTest | A | Contact plus manager notification, no user |
| StationScopeTest | A | Allow-list, own-stage writes, money stripping |
| UploadRulesTest | A | Customer gets the rules; unauthenticated → refused |
| AiDesignEditsTest | M | Studio AI routes require login |
| AiRecommendationTest / FeedbackTest | M | Ownership filter, source tagging (27 + 7 tests) |
| AuditLogTest | M | New audit actions |
| OrderLifecycleTest / OrderStatusLockTest / OrderWorkflowIntegrityTest | M | Lifecycle, down payment auto start, type lock |
| OwnerReadOnlyTest | M | Owner reads allowed areas, never writes (B8) |
| SecurityGateTest | M | Customer reads own order only; money fields hidden |

## 8. Prompt mapping

| Prompt | Asked | Backend evidence | Status |
|---|---|---|---|
| M1-B | Station admins | `StationScope.php`, assigned_stage migration, StationScopeTest (`c56a695`) | DONE |
| M2 | Request access | `CustomerContactController@requestAccess`, RequestAccessTest | DONE |
| M7 | Wizard/detail backend support | preferred_date, delivery_method, spec-sheet, upload-rules (`OrderController.php`) | PARTIAL (frontend side not audited) |
| M8 | Upload rules | `UploadRules.php`, UploadRulesTest | DONE |
| M14 | Security audit | `docs/SECURITY_AUDIT.md` | DONE |
| M14b | Security fixes | `edeabda`, AuthHardeningTest | DONE |
| M15 | API consistency, indexes | `c9c6428`, `docs/API.md`, 2 index migrations | DONE (both index migrations applied on live 2026-10-31) |
| S7 | Demo seeder | `DemoDataSeeder.php` | DONE |
| S11/F-AI | Source-aware AI | `AIController.php:88-130` | DONE |
| F-SPEC | Spec sheet | `pdf/spec-sheet.blade.php`, 2 routes | DONE (output not viewed, UNVERIFIED) |
| F-PROGRESS | Customer progress | No progress change in the backend diff (`git diff main...HEAD -- ProductionController.php`); customer reads existing order/production data | N-A backend / UNVERIFIED |
| B1 | Role labels in text | `UserController.php:173` "Administrator (Manager)" | PARTIAL (spot check only) |
| B2 | Optional preferred date, VFRB proposal | `OrderController.php:247,383`, proposal audit actions | DONE |
| B3 | Test fixes | 9 modified tests; 223 pass (HANDOFF) | DONE |
| B4 | Design lock | DesignLockTest, `AIController.php:46` | DONE |
| B5 | Customer contacts | CustomerContactController, migration, test | DONE |
| B6 | Message unread counts | 2 unread-count routes, MessageUnreadTest | DONE |
| B7 | Automation (shortage, RFQ, invoices) | MaterialShortageService, VfrbDailyDigest, autoIssueOnDelivery | DONE |
| B8 | Owner read-only check | OwnerReadOnlyTest, AdminRolesTest | DONE |
| B9 | Contract | `grep -i contract` in `OrderController.php` and `BillingService.php` finds only "subcontract"; no contract route or view | NOT STARTED |

## 9. Risks and open items

1. **Schema**: all 8 migrations are now on live (index migrations applied 2026-10-31). Live schema is ahead of production code; that is safe because the changes are additive. Never run plain `migrate` (the forbidden 2026_10_12 already ran, batch 38).
2. **Breaking API changes for old clients**: `/register` and the 4 production-incident routes are removed, Studio AI now needs a login, and the error JSON lost `status`. The backend and frontend must deploy together (HANDOFF production order).
3. **Token expiry and CORS**: `SANCTUM_EXPIRATION` (7 days) and `CORS_ALLOW_LOCALHOST` must be set or defaulted correctly on Vercel. Existing never-expiring tokens may now be rejected. UNVERIFIED on live.
4. **Postgres untested**: 223 tests ran on MySQL only. RLS, `hasIndex`, and the PDO options are PG-specific. The 2 newest commits are not in the 223 count.
5. **Preview writes live data**: the preview shares live DB vars, and Vercel SSO blocks it today (HANDOFF). Take a Supabase backup before the ff-merge.
6. Dev tooling committed (`.mcp.json`, `boost.json`, `laravel/boost` require-dev): harmless if `--no-dev` is used on Vercel. UNVERIFIED.
7. `production_incidents` table is orphaned (code deleted, no drop migration); the manuscript may still describe the feature.
8. Manuscript changes needed (HANDOFF Open 4): Station role with stage, request access, pickup and returns, auto production start, overdue flags, uploads, no self-registration.
9. Not verified here: the spec-sheet PDF render, the full order status list, ApiThrottle changes, F-PROGRESS (frontend), live AI with fabric (HANDOFF Open 3).
10. HANDOFF is stale (lists tip `c56a695`) and should be updated after this audit.
