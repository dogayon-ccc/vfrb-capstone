# VFRB system recap (2026-10-31)

Sources: `docs/RELEASE_AUDIT_BACKEND.md` (BA), `threejs/docs/RELEASE_AUDIT_FRONTEND.md` (FA), backend files at `c9c6428`, frontend HANDOFF (2026-10-30), manuscript `G60_Manuscript_Updated as of October 23, 2026.docx` (MS, read-only, paragraph text only). Anything not backed by these is marked UNVERIFIED.

## 1. Timeline

| Date | Milestone | Source |
|---|---|---|
| 2026-10-20 | Last production deploy: backend `main` 699c644, frontend `main` e248c9e | BA §1, FA §1 |
| 2026-10-21 | Client-material ledger migration applied on live (batch 46) | memory `live-migration-state` |
| 2026-10-22 | Backend `c6a3f8c` "wip": code-built 3D removed, 2D Build your own kept; customer contacts, ledger, spec sheet, AI and billing work, ProductionIncident removed | BA §2 |
| 2026-10-22 to 10-30 | Frontend: 3D removed (ed8625c), design language v2, admin nav, wizard split, Request access, PWA prompt (e83b8ed, d283940, 2074ec7, e4714a5, f26dcb5, 4d8b158). Per-day dates not in FA | FA §2 |
| 2026-10-29 | Backend `0975fcb` RLS, `assigned_stage`, `delivery_method`; `11be283` in-transit, returns, pickup, auto production start, overdue flags, PO/count safeguards; `c56a695` station admins, request access, upload rules, customer payload hardening, demo seeder | BA §2 |
| 2026-10-30 | Backend `edeabda` token expiry, uniform reset reply, CORS gate; `c9c6428` hot-path indexes, single error shape, `docs/API.md` | BA §2 |
| 2026-10-31 | Both audits written. Frontend build passes, logic checks 461/473. Nothing deployed yet | FA §8, HANDOFF |

## 2. Roles and permissions (backend-enforced)

Station = role `staff` with `users.assigned_stage` (`StationScope.php`). Owner = GET/HEAD only, on `OwnerReadOnly::AREAS` (dashboard, orders, reports, transactions, invoices, inventory, materials, activity-log, audit-logs).

| Area | Customer | Manager | Station | Owner |
|---|---|---|---|---|
| Orders | write own (create, view) | write | read (no money fields) | read |
| Proposals | write (accept or decline own) | write (propose) | none | read (part of orders) |
| Payments / invoices | read own billing (amount-free docs, `routes/api.php`) | write | none (money keys stripped) | read |
| Stock (inventory, materials) | none (reads the materials catalog for AI) | write | read | read |
| Output log | none | write | write own stage only (`STAGE_WRITES`) | none |
| QC | none | write | write only if stage = qc | none |
| Delivery | read own | write | none | none |
| Users | none | write (only Manager creates admin/owner, `UserController.php:173,242`) | none | none |
| Reports | none | read | none | read |
| Settings | write own profile | write | password only (`PUT admin/profile/password`) | none (not in AREAS) |

Frontend mirrors this in `jobAccess.js` (`STATION_PAGES`, `OWNER_PAGES`, `RequireManager`). The owner now sees the full `AdminDashboard`, and 4 owner logic checks fail (FA §8). The backend is the remaining guard.

## 3. End-to-end workflow

`[A]` = automatic (the system does it), `[M]` = person action.

1. [M] Guest submits **Request access** (`POST /api/request-access`, throttle 5/1). [A] Creates a `customer_contacts` row and notifies managers. No user is created (`CustomerContactController`, `RequestAccessTest`).
2. [M] Manager creates the customer account. [A] Password link email via `Password::sendResetLink` (`UserController.php:215`).
3. [M] Customer designs in the **Studio**: 2D vector flats, single piece or complete outfit. 3D is shown only as "3D (approx.)" or not at all (HANDOFF decisions; FA §7).
4. [M] **Order wizard**: sizes, optional preferred date (≥ 7 days, no rush), delivery method `pickup | vfrb_deliver | courier` (`OrderController.php:247,383`; `DeliveryController.php:95`).
5. [A] Material shortage warnings for the order (`MaterialShortageService`).
6. [M] Manager sends the **VFRB proposal** with the promised date (audit `order_proposal_sent`). [M] Customer accepts or declines (`customerAcceptProposal`).
7. Payment and production start:
   - Direct/bulk: [M] manager records the 80% down payment, then [A] `startProduction` runs and the order type locks (`OrderController.php:1000-1012`).
   - Subcontract: no down payment (422). [A] Goes straight into production; paid in full after delivery (`OrderController.php:982,1037`).
8. [M] Seven stages: pattern, segregation, cutting, sewing, QC, pressing, packing. Stations log output for their own stage, and QC is recorded at the QC stage. [A] Cutting gate checks the client-material ledger (`ClientMaterialService`).
9. [M] Packing, then delivery: `preparing → in_transit → delivered`, or pickup. Delivered requires production finished, an address, and client-material closure (`DeliveryController.php:305-313`).
10. [M] **Return**: `delivery_status=returned`, `delivered_qty=0` (`DeliveryController.php:379`).
11. [A] Invoice auto-issued on delivery (`autoIssueOnDelivery`, `DeliveryController.php:276-295`). [M] Receipts when the manager marks paid (`BillingController@markPaid`).
12. [A] Daily digest: `order_overdue` and `invoice_overdue` flagged once each; auto-RFQ for the low-stock threshold gap (`VfrbDailyDigest.php:47,119,263-280`).

## 4. Data and schema

| Migration | Table / change | On live | Source |
|---|---|---|---|
| 2026_10_20_000001_create_customer_contacts_table | new `customer_contacts` | YES, batch 45 | live `migrate:status` run by the user 2026-10-31 |
| 2026_10_21_000001_add_client_material_ledger | `materials.ownership`, new `client_material_ledger` | YES, batch 46 | HANDOFF, memory |
| 2026_10_22_000001_add_design_3d_snapshot_to_orders | `orders.client_design_3d_snapshot_file` | YES, batch 47 | live `migrate:status` run by the user 2026-10-31 |
| 2026_10_22_000002_enable_rls_on_flagged_tables | RLS on 6 tables (pgsql only) | YES, batch 48 | HANDOFF |
| 2026_10_25_000001_add_assigned_stage_to_users | `users.assigned_stage` | YES, batch 49 | HANDOFF |
| 2026_10_29_000001_add_delivery_method_to_orders | `orders.delivery_method` | YES, batch 50 | HANDOFF |
| 2026_10_30_000001_add_hot_path_indexes | 4 indexes (orders, order_messages, notifications, order_production_tracking) | YES, batch 51 | live `migrate:status` 2026-10-31 |
| 2026_10_30_000002_add_tracking_updated_index | `idx_opt_updated` | YES, batch 52 | live `migrate:status` 2026-10-31 |

Data already changed on live: fabric ownership (ids 61-68 = vfrb, 55-60 Airstretch = client) (HANDOFF). The `production_incidents` table stays orphaned; there is no drop migration (BA §9).

## 5. API and frontend map

| Endpoint (new) | Frontend caller | Source |
|---|---|---|
| POST /api/request-access | `pages/auth/RequestAccess.jsx` (linked from Login) | FA §4-5 |
| GET /api/customer/upload-rules | `utils/uploadRules.js` (Studio, wizard) | FA §5, §7 |
| GET /api/customer/messages/unread-count | `hooks/useUnreadMessages` | FA §3, §5 |
| GET /api/admin/messages/unread-count | `useUnreadMessages`, `NavBadge` | FA §3, §7 |
| GET/POST /api/admin/customer-contacts | Admin customer page. Exact screen UNVERIFIED | FA §5 |
| GET /api/admin/inventory/client-stock | Admin inventory. Exact screen UNVERIFIED | FA §5 |
| GET/POST /api/admin/orders/{id}/client-materials | `components/admin/ClientMaterialsPanel` | FA §3, §5 |
| GET /api/customer/orders/{id}/spec-sheet | Customer `OrderDetail.jsx` | FA §5, §7 |
| GET /api/admin/orders/{id}/spec-sheet | No frontend caller listed in FA. UNVERIFIED | BA §4 |
| Removed: /register, /admin/dashboard/staff, 4 production-incident routes | `Register.jsx`, `StaffDashboard.jsx` deleted; `/register` redirects to `/login` | BA §4, FA §3-4 |
| Changed: /ai/describe-design, /ai/design-edits now need a login | Studio AI tool | BA §4 |

## 6. AI scope as built

- Recommends raw material **types** only (category, unit, specifications) for an order. No quantity, yardage, price or quota (`AIController.php:415,437,663`).
- Studio design edits are the one approved exception: validated design edits only, never prices, quantities or sizes (`AIController.php:511,551`).
- Never suggests client-owned stock: filter `ownership='vfrb'` (`AIController.php:90`). Materials locked → 409.
- Source-aware: every result is tagged `source` = `ai` | `cache` | `catalog_default` (`AIController.php:111-130,162`).
- Fallback: a cached result first, then the catalog default when the AI call fails (`AIController.php:111-130`).
- Out of scope: photo-to-3D generation (HANDOFF decision). Live AI with fabric is UNVERIFIED (HANDOFF Open 3).

## 7. Security posture

- **Auth:** Sanctum tokens expire after 7 days (`SANCTUM_EXPIRATION`, default 10080). Deactivated admin/station accounts cannot sign in. Forgot-password gives the same reply either way. No self-registration (BA §6, `config/sanctum.php`).
- **Role gates:** `role:` middleware, `owner.readonly` (GET on AREAS), `station` (allow-list, own-stage writes, money stripping), `jobfn:` on inventory/production routes (`routes/api.php`).
- **Uploads:** reference uploads limited to jpg/jpeg/png/pdf, ≤ 5120 KB (`UploadRules.php`); content-sniff logic check PASS (FA §9).
- **Throttles:** login 10/1, request-access 5/1, reset 5/1, AI 30/1 and 20/1, admin `auth.throttle:staff,120`, customer 60 (`routes/api.php`).
- **Customer data removal:** the customer order payload drops agreed_total, discounts, down payment, payment terms and QC fields (`OrderController.php:151`). Billing documents carry no amounts. Stations get `MONEY_KEYS` stripped.
- **Errors:** JSON `{message}` only; 5xx text is fixed unless `APP_DEBUG` (`bootstrap/app.php`).
- **CORS:** localhost only when `CORS_ALLOW_LOCALHOST` is on (default: local env) (`config/cors.php`).
- **Audit log:** new actions (proposal sent/declined, down payment, client_material ×3, customer_contact_created, access_requested); unknown actions are rejected (`AuditLog.php:23-33`).
- **RLS:** enabled on invoices, payment_receipts, audit_logs, customer_contacts, client_material_ledger, recommendation_guidance (migration 2026_10_22_000002, applied batch 48).

## 8. Known gaps

- **Tests:** backend 223 pass on MySQL at `c56a695` (HANDOFF); the 2 later commits are not in that count. PostgreSQL behaviour is UNVERIFIED.
- **Frontend checks:** 12 of 473 fail. Two are real risks (owner sees revenue and totals; owner write controls are not hidden), others are stale or UNVERIFIED (FA §8).
- **Live schema:** all 8 migrations are on live (§4); none pending.
- **Blocked:** S5 Tripo GLB intake, pending the licence and third-party reference photos (HANDOFF Open 1). Fabric ownership is set only for ids 55-68; other client-supplied fabrics are UNVERIFIED.
- **Not started:** B9 contract (BA §8).
- **UNVERIFIED:** spec-sheet PDF render, full order status list, ApiThrottle changes, F-PROGRESS, set-order wizard, Output Log with rows, live AI with fabric, PWA prompt on live, live URLs and deploy config.
- **Dirty frontend tree:** 17 staged deletions, 5 unstaged edits, 6 untracked photos (FA §1).

## 9. Manuscript delta

| Manuscript location | Paper says now | System does now |
|---|---|---|
| Ch 1 Definition of Terms, "Customer Account" and "Direct Customer" | Customers are "registered" and "registered as customer accounts" | No self-registration. Request access creates a contact, and a manager creates the account (`routes/api.php` `/register` removed; `CustomerContactController`) |
| Ch 3 Fig 3-11 Login description | "new customers can register from the same page" | `/register` redirects to `/login`; Login links to Request access (FA §4) |
| Ch 3 Fig 3-4 Context Diagram | Three entities: Customer, Administrator, Owner | Administrator is split into Manager and Station (`assigned_stage`); Request access is a guest input (`StationScope.php`) |
| Ch 3 Fig 3-9 Use Case Diagram | Customer "registers"; Administrator "records QC checklists and incidents"; one Administrator actor | No register use case; production incidents removed (4 routes deleted, BA §4); Station actor limited to own-stage output/QC |
| Ch 3 Fig 3-18 Admin Orders description | Station-level administrators advance orders through seven stages | Stations log output and QC for their assigned stage only; advance and confirm are manager routes (`STAGE_WRITES`, ALLOWED list) |
| Ch 3 Fig 3-17 Admin Dashboard description | Sidebar lists an "incidents" module; one admin dashboard | Incidents removed; the same dashboard for all admin roles, with money stripped for stations (FA §4, `MONEY_KEYS`) |
| Ch 3 Fig 3-5 DFD Level 1 | Delivery, Sales Recording and Reports; no access-request flow, no client-material store | Adds `customer_contacts` and `client_material_ledger` stores, return and pickup flows, auto invoice on delivery (BA §5-6) |
| Ch 3 Fig 3-7 Proposed System Flowchart | Login → design → wizard → admin confirm → stock → stages → QC → pressing, packing, delivery, invoice | Adds VFRB proposal and customer accept, down payment → [A] production start (subcontract direct), in transit, returns, pickup, [A] invoice, [A] overdue flags (§3) |
| Ch 3 Fig 3-6 / Phase I constraints (80/20, OTG) | Direct 80% down, 20% on delivery; OTG paid after weekly delivery | Same 80/20 for direct/bulk. Subcontract: no down payment, paid in full after delivery, starts production at once (`OrderController.php:62,982,1037`) |
| Ch 3 Fig 3-15 Order Wizard description | "delivery deadline decided by the customer" | Preferred date optional, ≥ 7 days; VFRB proposes the promised date; pickup or delivery method (`OrderController.php:247,383`, `DeliveryController.php:95`) |
| Ch 3 Fig 3-8 ERD | Lists 19 tables; no customer_contacts, client_material_ledger, assigned_stage, delivery_method | 2 new tables and 3 new columns (§4) |
| Ch 3 Fig 3-13 Design Studio description | "upload their own design"; Text, Logo, Draw, AI tools | Upload limited to jpg/jpeg/png/pdf ≤ 5 MB (`UploadRules.php`); tool rail V H I G B E T U (FA §7); "start from a photo" not built (HANDOFF) |
| Ch 3 Fig 3-14 3D Preview description | "available only for garments that have a verified 3D model" | 0 verified models; code-built 3D removed; 3D shown as "3D (approx.)" or not available (HANDOFF decisions, FA §3) |
| Ch 3 Fig 3-16 AI Recommendations description | Types only, no quantities or prices | Same, plus the `source` tag (ai/cache/catalog_default) and client-owned stock excluded (`AIController.php:90,130`) |
| Ch 3 Fig 3-10 to 3-18 screenshots | Old UI | Design language v2, navy customer shell, new admin nav; re-screenshot after the UI freeze (HANDOFF Open 4) |
| Ch 3 Phase IV Testing | "18 test files with about 150 test methods"; workflow "from customer registration" | 223 tests pass on MySQL (HANDOFF); 13 test files added and 9 changed (BA §7); there is no registration step |
| Ch 3 Fig 3-4 / 3-17 Owner role | Owner has read-only access to reports, analytics, audit log | Backend still read-only (GET on 9 areas), but the frontend now gives the owner the full dashboard (FA §10 risk 2) |
| Ch 1 Fig 1-1 Conceptual Framework (outputs) | Low-stock alerts, reports | Adds automatic RFQs, overdue flags, auto invoices (`VfrbDailyDigest.php`, `DeliveryController.php`) |

## 10. Release checklist delta

- **Order:** Supabase backup → backend ff-merge and push → wait for Ready → frontend ff-merge and push → verify → tag `defense-2026-11` (HANDOFF). The frontend must not ship first: it calls 8 new endpoints (FA §10).
- **Migrations:** none pending; both index migrations were applied 2026-10-31. Never run plain `migrate` (2026_10_12 already ran, batch 38).
- **Env vars (backend):** `SANCTUM_EXPIRATION` (or the 10080 default), `CORS_ALLOW_LOCALHOST` off in production, `FRONTEND_URL` set, `APP_DEBUG=false`, optional `DB_PERSISTENT` / `DB_EMULATE_PREPARES`. Re-enter "Needs Attention" vars as Sensitive with the SAME value; never change `APP_KEY`.
- **Env vars (frontend):** no new ones (`VITE_API_URL`, `VITE_MODEL_BASE_URL`) (FA §6).
- **CORS:** production origin = `FRONTEND_URL` only. The preview is blocked by Vercel SSO (302), so turn protection off only for a short live click-through.
- **Data fixes:** fabric ownership is done for ids 55-68; others UNVERIFIED. Set `assigned_stage` for every station user (UNVERIFIED which users). Existing tokens may expire under the new limit.
- **Frontend tree:** commit or discard the staged deletions, unstaged edits and root photos before the merge (FA §1).
- **Smoke routes:** `POST /api/request-access`, `POST /api/login`, `POST /api/admin/login` (manager, station, owner), `GET /api/customer/upload-rules`, `GET /api/customer/messages/unread-count`, `GET /api/customer/orders/{id}/spec-sheet`, `GET /api/admin/orders/{id}/client-materials`, `PATCH /api/admin/delivery/{id}/status` (in_transit, returned), an owner POST (expect 403), a station write on another stage (expect 403), and the PWA update prompt on the live site.
