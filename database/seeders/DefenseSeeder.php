<?php
// database/seeders/DefenseSeeder.php
// VFRB Enterprise — Defense Seeder v11
//
// FIXED from v10:
//   - REMOVED: use App\Models\MaterialFormula (model deleted — was crashing seeder)
//   - REMOVED: Section 6 material formula creation (table deleted per master prompt)
//   - ADDED:   Section 6 — demo orders across all 7 production stages
//   - ADDED:   production tracking rows per order (ensureProductionTracking)
//   - ADDED:   Roxanne manager account (per master prompt)
//
// CRITICAL COLUMN NAMES (enforced throughout):
//   suppliers.supplier_name (NOT company_name)
//   suppliers.payment_terms_with_supplier (NOT payment_terms)
//   inventory_logs.recorded_by (NOT performed_by)
//   inventory_logs.change_qty (NOT quantity_change)
//   users PK is user_id (NOT id)
//   delivery_tracking uses delivery_status (NOT status)
//   materials uses unit_cost (NOT unit_price)
//   materials uses reorder_threshold (NOT reorder_point)
//   notifications uses notif_id (NOT id)
//   order_messages uses body (NOT message)
//   NO material_formulas table — permanently deleted
//   NO order_size_breakdown table — permanently deleted
//
// Run: php artisan db:seed --class=DefenseSeeder
// Safe to re-run — uses firstOrCreate / updateOrCreate throughout.

namespace Database\Seeders;

use App\Models\Design;
use App\Models\Material;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DefenseSeeder extends Seeder
{
    // 7-stage production pipeline (locked order)
    private const PROD_STAGES = [
        'pattern', 'segregation', 'cutting', 'sewing', 'qc', 'pressing', 'packing',
    ];

    public function run(): void
    {
        $this->command->info('🌱 DefenseSeeder v11 — VFRB Enterprise');
        $this->command->info('MaterialFormula removed. Orders across all 7 stages added.');

        // ── 1. ROLES ──────────────────────────────────────────────────────────
        $this->command->info('Creating roles...');

        foreach (['manager', 'staff', 'customer'] as $role) {
            \Spatie\Permission\Models\Role::firstOrCreate(
                ['name' => $role, 'guard_name' => 'web']
            );
        }

        // ── 2. TEST ACCOUNTS ──────────────────────────────────────────────────
        $this->command->info('Creating defense accounts...');

        $accounts = [
            [
                'name'     => 'VFRB Manager',
                'email'    => 'vfrbmanager@gmail.com',
                'password' => 'VFRBmanager2026!',
                'role'     => 'manager',
            ],
            [
                'name'     => 'Roxanne',
                'email'    => 'roxanne@vfrbenterprise.com',
                'password' => 'VFRBRoxanne2026!',
                'role'     => 'manager',
            ],
            [
                'name'     => 'VFRB Staff',
                'email'    => 'vfrbstaff@gmail.com',
                'password' => 'VFRBstaff2026!',
                'role'     => 'staff',
            ],
            [
                'name'              => 'Test Customer',
                'email'             => 'testcustomer@gmail.com',
                'password'          => 'TestPass2026!',
                'role'              => 'customer',
                'organization_name' => 'Test School Inc.',
                'client_type'       => 'school',
                'contact_number'    => '09171234567',
                'address'           => 'Quezon City, Metro Manila',
            ],
            [
                'name'              => 'OTG Hospital',
                'email'             => 'otghospital@gmail.com',
                'password'          => 'OTGPass2026!',
                'role'              => 'customer',
                'organization_name' => 'OTG Scrubs Inc.',
                'client_type'       => 'medical',
                'contact_number'    => '09281234567',
                'address'           => 'Makati City, Metro Manila',
            ],
            [
                'name'              => 'PNG Schools',
                'email'             => 'pngschool@gmail.com',
                'password'          => 'PNGPass2026!',
                'role'              => 'customer',
                'organization_name' => 'Papua New Guinea School District',
                'client_type'       => 'school',
                'contact_number'    => '09391234567',
                'address'           => 'Papua New Guinea',
            ],
        ];

        $userMap = [];
        foreach ($accounts as $acc) {
            $user = User::updateOrCreate(
                ['email' => $acc['email']],
                [
                    'name'              => $acc['name'],
                    'password'          => Hash::make($acc['password']),
                    'email_verified_at' => now(),
                    'organization_name' => $acc['organization_name'] ?? null,
                    'client_type'       => $acc['client_type'] ?? null,
                    'contact_number'    => $acc['contact_number'] ?? null,
                    'address'           => $acc['address'] ?? null,
                ]
            );

            // Assign role only if not already assigned
            $hasRole = DB::table('model_has_roles')
                ->where('model_id', $user->user_id)
                ->where('model_type', 'App\\Models\\User')
                ->exists();

            if (!$hasRole) {
                $user->assignRole($acc['role']);
            }

            $userMap[$acc['email']] = $user;
            $this->command->line("  ✅ {$acc['email']} → {$acc['role']}");
        }

        $customer1 = $userMap['testcustomer@gmail.com'];
        $customer2 = $userMap['otghospital@gmail.com'];
        $customer3 = $userMap['pngschool@gmail.com'];
        $staffUser  = $userMap['vfrbstaff@gmail.com'];
        $managerUser = $userMap['vfrbmanager@gmail.com'];

        // ── 3. SUPPLIER MASTER DATA (admin-internal, no login) ────────────────
        $this->command->info('Creating supplier master data...');

        $suppliers = [
            [
                'supplier_name'               => 'Myunchant Valenzuela',
                'contact_person'              => 'Contact Person',
                'email'                       => 'myunchant@supplier.test',
                'phone'                       => '02-8123-4567',
                'address'                     => 'Valenzuela City, Metro Manila',
                'supplier_type'               => 'direct',
                'materials_supplied'          => 'Sinulid (thread), fabric, accessories',
                'payment_terms_with_supplier' => 'cash',
                'lead_time_days'              => 3,
                'is_active'                   => true,
            ],
            [
                'supplier_name'               => 'OTG Company',
                'contact_person'              => 'OTG Representative',
                'email'                       => 'otg@supplier.test',
                'phone'                       => '02-8765-4321',
                'address'                     => 'Makati City, Metro Manila',
                'supplier_type'               => 'subcontract',
                'materials_supplied'          => 'Complete scrub suit materials, patterns, cutouts',
                'payment_terms_with_supplier' => 'net_30',
                'lead_time_days'              => 7,
                'is_active'                   => true,
            ],
            [
                'supplier_name'               => 'Manila Bay Thread Supply',
                'contact_person'              => 'Thread Supplier',
                'email'                       => 'mbt@supplier.test',
                'phone'                       => '0917-000-0001',
                'address'                     => 'Manila',
                'supplier_type'               => 'direct',
                'materials_supplied'          => 'Polyester thread, elastic band',
                'payment_terms_with_supplier' => 'cash',
                'lead_time_days'              => 2,
                'is_active'                   => true,
            ],
        ];

        foreach ($suppliers as $s) {
            Supplier::firstOrCreate(
                ['supplier_name' => $s['supplier_name']],
                $s
            );
            $this->command->line("  ✅ Supplier: {$s['supplier_name']}");
        }

        // ── 4. MATERIAL MASTER DATA ───────────────────────────────────────────
        // Values from Ma'am Fe interview, April 30, 2026
        $this->command->info('Creating material master data...');

        $materials = [
            // Fabric (3.5 yards per garment — Gemini AI context only, not shown to customer)
            ['material_name' => 'Airstretch Fabric',              'category' => 'Fabric',      'unit' => 'yards',   'quantity_in_stock' => 500.00, 'reorder_threshold' => 100.00, 'unit_cost' => 85.00],
            ['material_name' => 'Cotton Fabric',                  'category' => 'Fabric',      'unit' => 'yards',   'quantity_in_stock' => 300.00, 'reorder_threshold' =>  80.00, 'unit_cost' => 65.00],
            ['material_name' => 'Polyester-Cotton Blend Fabric',  'category' => 'Fabric',      'unit' => 'yards',   'quantity_in_stock' => 200.00, 'reorder_threshold' =>  60.00, 'unit_cost' => 75.00],
            // Thread (350m per garment — low stock for demo alert)
            ['material_name' => 'Polyester Thread',               'category' => 'Thread',      'unit' => 'kg',      'quantity_in_stock' =>  12.00, 'reorder_threshold' =>  20.00, 'unit_cost' => 450.00],
            ['material_name' => 'Cotton Thread',                  'category' => 'Thread',      'unit' => 'kg',      'quantity_in_stock' =>   8.00, 'reorder_threshold' =>  15.00, 'unit_cost' => 380.00],
            // Elastic (waist - 3 inches — low for demo alert)
            ['material_name' => 'Elastic Band (1-inch)',          'category' => 'Elastic',     'unit' => 'meters',  'quantity_in_stock' =>  12.00, 'reorder_threshold' =>  30.00, 'unit_cost' =>  15.00],
            // Trim
            ['material_name' => 'V-Neck Attachment Strip',        'category' => 'Trim',        'unit' => 'pieces',  'quantity_in_stock' => 200.00, 'reorder_threshold' =>  50.00, 'unit_cost' =>   8.00],
            // Accessories
            ['material_name' => 'Logo Patch',                     'category' => 'Accessories', 'unit' => 'pieces',  'quantity_in_stock' => 150.00, 'reorder_threshold' =>  30.00, 'unit_cost' =>  25.00],
            ['material_name' => 'Buttons (Set)',                   'category' => 'Accessories', 'unit' => 'sets',    'quantity_in_stock' => 500.00, 'reorder_threshold' => 100.00, 'unit_cost' =>   5.00],
            ['material_name' => 'Zipper (30cm)',                  'category' => 'Accessories', 'unit' => 'pieces',  'quantity_in_stock' => 300.00, 'reorder_threshold' =>  50.00, 'unit_cost' =>  12.00],
            // Lining
            ['material_name' => 'Satin Lining',                   'category' => 'Fabric',      'unit' => 'yards',   'quantity_in_stock' => 100.00, 'reorder_threshold' =>  25.00, 'unit_cost' =>  45.00],
            ['material_name' => 'Interlining',                    'category' => 'Fabric',      'unit' => 'yards',   'quantity_in_stock' =>  80.00, 'reorder_threshold' =>  20.00, 'unit_cost' =>  35.00],
        ];

        foreach ($materials as $m) {
            Material::firstOrCreate(
                ['material_name' => $m['material_name']],
                $m
            );
            $this->command->line("  ✅ Material: {$m['material_name']}");
        }

        // ── 5. INTERNAL DESIGN TEMPLATES ─────────────────────────────────────
        $this->command->info('Creating VFRB internal design templates...');

        $designs = [
            ['design_name' => '[VFRB Template] Scrub Top',      'garment_type' => 'scrub_top',   'category' => 'Medical / Scrubs',    'collar_type' => 'v-neck',   'sleeve_type' => 'short',     'pocket_type' => 'left_chest', 'color' => 'teal'],
            ['design_name' => '[VFRB Template] Scrub Pants',    'garment_type' => 'scrub_pants', 'category' => 'Medical / Scrubs',    'collar_type' => 'none',     'sleeve_type' => 'sleeveless', 'pocket_type' => 'side_x2',   'color' => 'teal'],
            ['design_name' => '[VFRB Template] School Polo',    'garment_type' => 'polo_shirt',  'category' => 'School Uniform',      'collar_type' => 'polo',     'sleeve_type' => 'short',     'pocket_type' => 'left_chest', 'color' => 'white'],
            ['design_name' => '[VFRB Template] School Blouse',  'garment_type' => 'blouse',      'category' => 'School Uniform',      'collar_type' => 'mandarin', 'sleeve_type' => 'short',     'pocket_type' => 'none',      'color' => 'white'],
            ['design_name' => '[VFRB Template] Corporate Polo', 'garment_type' => 'polo_shirt',  'category' => 'Corporate Uniform',   'collar_type' => 'polo',     'sleeve_type' => 'short',     'pocket_type' => 'left_chest', 'color' => 'navy'],
            ['design_name' => '[VFRB Template] PE Shirt',       'garment_type' => 'polo_shirt',  'category' => 'PE / Sports',         'collar_type' => 'round',    'sleeve_type' => 'short',     'pocket_type' => 'none',      'color' => 'royal_blue'],
        ];

        $designMap = [];
        foreach ($designs as $d) {
            $design = Design::firstOrCreate(
                ['design_name' => $d['design_name']],
                $d
            );
            $designMap[$d['design_name']] = $design;
            $this->command->line("  ✅ Design: {$d['design_name']}");
        }

        // ── 6. DEMO ORDERS — one per production stage + completed + pending ───
        // Purpose: judges see a live system, not empty screens.
        // Each order is in a different stage so every page has data.
        // DSA note: STATUS_PIPELINE is an ordered array — index = sequence.
        $this->command->info('Creating demo orders across all 7 production stages...');

        $demoOrders = [
            // Stage 1 — Pattern
            [
                'user_id'          => $customer1->user_id,
                'status'           => 'pattern',
                'order_type'       => 'direct',
                'garment_type'     => 'polo_shirt',
                'collar_type'      => 'polo',
                'sleeve_type'      => 'short',
                'pocket_type'      => 'left_chest',
                'color'            => 'navy blue',
                'quantity_ordered' => 100,
                'sizing_type'      => 'standard',
                'payment_method'   => 'bank_transfer',
                'payment_terms'    => 'down_payment',
                'po_reference'     => 'PO-2026-001',
                'notes'            => 'School polo for Test School Inc. — Grade 7 to 12',
                'qc_required'      => 1,
                'target_delivery_date' => now()->addDays(21)->toDateString(),
            ],
            // Stage 2 — Segregation
            [
                'user_id'          => $customer2->user_id,
                'status'           => 'segregation',
                'order_type'       => 'subcontract',
                'garment_type'     => 'scrub_top',
                'collar_type'      => 'v-neck',
                'sleeve_type'      => 'short',
                'pocket_type'      => 'left_chest',
                'color'            => 'ceil blue',
                'quantity_ordered' => 250,
                'sizing_type'      => 'custom',
                'payment_method'   => 'bank_transfer',
                'payment_terms'    => 'full_payment',
                'po_reference'     => 'PO-OTG-2026-044',
                'notes'            => 'OTG Scrubs — Ceil blue scrub tops for hospital staff',
                'qc_required'      => 1,
                'target_delivery_date' => now()->addDays(14)->toDateString(),
            ],
            // Stage 3 — Cutting
            [
                'user_id'          => $customer1->user_id,
                'status'           => 'cutting',
                'order_type'       => 'direct',
                'garment_type'     => 'polo_shirt',
                'collar_type'      => 'polo',
                'sleeve_type'      => 'short',
                'pocket_type'      => 'none',
                'color'            => 'white',
                'quantity_ordered' => 150,
                'sizing_type'      => 'standard',
                'payment_method'   => 'bank_transfer',
                'payment_terms'    => 'down_payment',
                'po_reference'     => 'PO-2026-002',
                'notes'            => 'School blouse order — girls uniform',
                'qc_required'      => 1,
                'target_delivery_date' => now()->addDays(18)->toDateString(),
            ],
            // Stage 4 — Sewing
            [
                'user_id'          => $customer2->user_id,
                'status'           => 'sewing',
                'order_type'       => 'subcontract',
                'garment_type'     => 'scrub_pants',
                'collar_type'      => 'none',
                'sleeve_type'      => 'sleeveless',
                'pocket_type'      => 'side_x2',
                'color'            => 'ceil blue',
                'quantity_ordered' => 250,
                'sizing_type'      => 'custom',
                'payment_method'   => 'bank_transfer',
                'payment_terms'    => 'full_payment',
                'po_reference'     => 'PO-OTG-2026-045',
                'notes'            => 'OTG Scrubs — matching scrub pants for PO-OTG-2026-044',
                'qc_required'      => 1,
                'target_delivery_date' => now()->addDays(10)->toDateString(),
            ],
            // Stage 5 — QC
            [
                'user_id'          => $customer3->user_id,
                'status'           => 'qc',
                'order_type'       => 'bulk',
                'garment_type'     => 'polo_shirt',
                'collar_type'      => 'polo',
                'sleeve_type'      => 'short',
                'pocket_type'      => 'left_chest',
                'color'            => 'bottle green',
                'quantity_ordered' => 500,
                'sizing_type'      => 'standard',
                'payment_method'   => 'bank_transfer',
                'payment_terms'    => 'net_30',
                'po_reference'     => 'PO-PNG-2026-001',
                'notes'            => 'Papua New Guinea school uniform export — 500 pcs',
                'qc_required'      => 1,
                'target_delivery_date' => now()->addDays(7)->toDateString(),
            ],
            // Stage 6 — Pressing
            [
                'user_id'          => $customer1->user_id,
                'status'           => 'pressing',
                'order_type'       => 'rush',
                'garment_type'     => 'polo_shirt',
                'collar_type'      => 'polo',
                'sleeve_type'      => 'short',
                'pocket_type'      => 'left_chest',
                'color'            => 'maroon',
                'quantity_ordered' => 80,
                'sizing_type'      => 'standard',
                'payment_method'   => 'gcash',
                'payment_terms'    => 'full_payment',
                'po_reference'     => 'RUSH-2026-001',
                'notes'            => 'Rush order — school event this weekend',
                'qc_required'      => 1,
                'qc_passed_at'     => now()->subHours(3)->toDateTimeString(),
                'target_delivery_date' => now()->addDays(2)->toDateString(),
            ],
            // Stage 7 — Packing
            [
                'user_id'          => $customer2->user_id,
                'status'           => 'packing',
                'order_type'       => 'subcontract',
                'garment_type'     => 'scrub_top',
                'collar_type'      => 'v-neck',
                'sleeve_type'      => 'short',
                'pocket_type'      => 'left_chest',
                'color'            => 'surgical green',
                'quantity_ordered' => 100,
                'sizing_type'      => 'custom',
                'payment_method'   => 'bank_transfer',
                'payment_terms'    => 'full_payment',
                'po_reference'     => 'PO-OTG-2026-038',
                'notes'            => 'OTG Scrubs repeat order — Makati hospital',
                'qc_required'      => 1,
                'qc_passed_at'     => now()->subHours(8)->toDateTimeString(),
                'target_delivery_date' => now()->addDays(1)->toDateString(),
            ],
            // Completed (with delivery record)
            [
                'user_id'          => $customer3->user_id,
                'status'           => 'completed',
                'order_type'       => 'bulk',
                'garment_type'     => 'polo_shirt',
                'collar_type'      => 'polo',
                'sleeve_type'      => 'short',
                'pocket_type'      => 'left_chest',
                'color'            => 'royal blue',
                'quantity_ordered' => 200,
                'sizing_type'      => 'standard',
                'payment_method'   => 'bank_transfer',
                'payment_terms'    => 'net_30',
                'po_reference'     => 'PO-PNG-2025-012',
                'notes'            => 'Completed PNG school order — paid and delivered',
                'qc_required'      => 1,
                'qc_passed_at'     => now()->subDays(5)->toDateTimeString(),
                'target_delivery_date' => now()->subDays(3)->toDateString(),
            ],
            // Pending (customer just placed, awaiting confirmation)
            [
                'user_id'          => $customer1->user_id,
                'status'           => 'pending',
                'order_type'       => 'direct',
                'garment_type'     => 'polo_shirt',
                'collar_type'      => 'polo',
                'sleeve_type'      => 'short',
                'pocket_type'      => 'left_chest',
                'color'            => 'sky blue',
                'quantity_ordered' => 60,
                'sizing_type'      => 'standard',
                'payment_method'   => 'not_specified',
                'payment_terms'    => 'not_specified',
                'po_reference'     => null,
                'notes'            => 'New inquiry — uniform for incoming Grade 7',
                'qc_required'      => 1,
                'target_delivery_date' => now()->addDays(30)->toDateString(),
            ],
        ];

        $orderIds = [];
        foreach ($demoOrders as $orderData) {
            // Check if a demo order in this status already exists for this user
            $exists = DB::table('orders')
                ->where('user_id', $orderData['user_id'])
                ->where('status', $orderData['status'])
                ->where('po_reference', $orderData['po_reference'])
                ->exists();

            if ($exists) {
                $this->command->line("  ⏭  Order [{$orderData['status']}] already exists — skipping");
                continue;
            }

            $orderId = DB::table('orders')->insertGetId(array_merge($orderData, [
                'created_at' => now()->subDays(rand(1, 14)),
                'updated_at' => now(),
            ]));

            $orderIds[] = ['id' => $orderId, 'status' => $orderData['status'], 'qty' => $orderData['quantity_ordered']];
            $this->command->line("  ✅ Order #{$orderId} — {$orderData['status']} — {$orderData['garment_type']} ({$orderData['color']})");

            // Create production tracking rows for production-stage orders
            $stagesIdx = array_search($orderData['status'], self::PROD_STAGES);

            if ($stagesIdx !== false) {
                foreach (self::PROD_STAGES as $i => $stage) {
                    $qtyCompleted = 0;

                    if ($i < $stagesIdx) {
                        // Completed stages: full quantity
                        $qtyCompleted = $orderData['quantity_ordered'];
                    } elseif ($i === $stagesIdx) {
                        // Current stage: 60–90% complete for a realistic demo
                        $qtyCompleted = (int) round($orderData['quantity_ordered'] * rand(60, 90) / 100);
                    }
                    // Future stages: 0

                    DB::table('order_production_tracking')->updateOrInsert(
                        ['order_id' => $orderId, 'stage' => $stage],
                        [
                            'qty_target'    => $orderData['quantity_ordered'],
                            'qty_completed' => $qtyCompleted,
                            'updated_by'    => $staffUser->user_id,
                            'notes'         => $i < $stagesIdx ? 'Completed' : ($i === $stagesIdx ? 'In progress' : null),
                            'created_at'    => now()->subDays(rand(1, 7)),
                            'updated_at'    => now(),
                        ]
                    );
                }
                $this->command->line("    ↳ Production tracking rows created");
            }

            // Auto-create delivery record for completed orders
            if ($orderData['status'] === 'completed') {
                DB::table('delivery_tracking')->updateOrInsert(
                    ['order_id' => $orderId],
                    [
                        'delivery_method'         => 'vfrb_deliver',
                        'delivery_status'         => 'delivered',     // delivery_status NOT status
                        'delivery_address'        => 'Quezon City, Metro Manila',
                        'estimated_delivery_date' => now()->subDays(3)->toDateString(),
                        'actual_delivery_date'    => now()->subDays(2)->toDateString(),
                        'updated_by'              => $staffUser->user_id,
                        'notes'                   => 'Seeded — completed demo order',
                        'created_at'              => now()->subDays(5),
                        'updated_at'              => now(),
                    ]
                );
                $this->command->line("    ↳ Delivery record created");
            }
        }

        // ── 7. SALES TRANSACTIONS for completed orders ────────────────────────
        $this->command->info('Creating sales transaction records...');

        // sales_transactions schema (from vfrb_db.sql):
        //   transaction_id, order_id, processed_by, amount_total, amount_paid,
        //   balance_due, payment_method, payment_terms, payment_date, or_number,
        //   completion_status, notes, date_processed, created_at, updated_at
        foreach ($orderIds as $o) {
            if ($o['status'] !== 'completed') continue;

            $amountTotal = $o['qty'] * 350; // rough ₱350 per piece

            DB::table('sales_transactions')->updateOrInsert(
                ['order_id' => $o['id']],  // no transaction_type column in DB
                [
                    'order_id'          => $o['id'],
                    'processed_by'      => $managerUser->user_id,  // correct column
                    'amount_total'      => $amountTotal,
                    'amount_paid'       => $amountTotal,           // fully paid
                    'balance_due'       => 0.00,
                    'payment_method'    => 'bank_transfer',
                    'payment_terms'     => 'full_payment',         // valid ENUM value
                    'payment_date'      => now()->subDays(2)->toDateString(),
                    'or_number'         => 'OR-' . strtoupper(substr(md5($o['id']), 0, 8)),
                    'completion_status' => 'completed',
                    'notes'             => 'Seeded transaction — defense demo',
                    'date_processed'    => now()->subDays(2),
                    'created_at'        => now()->subDays(2),
                    'updated_at'        => now(),
                ]
            );
        }

        // ── 8. NOTIFICATIONS ─────────────────────────────────────────────────
        $this->command->info('Creating demo notifications...');

        $notifs = [
            [
                'user_id'    => $customer1->user_id,
                'order_id'   => null,
                'type'       => 'order_update',
                'title'      => 'Order in Production',
                'message'    => '🎉 Your polo shirt order is now in the Cutting stage.',
                'is_read'    => 0,
                'date_sent'  => now()->subHours(2),
            ],
            [
                'user_id'    => $customer2->user_id,
                'order_id'   => null,
                'type'       => 'order_update',
                'title'      => 'QC Passed',
                'message'    => '✅ Your scrub top order passed Quality Control and is being pressed.',
                'is_read'    => 0,
                'date_sent'  => now()->subHours(1),
            ],
            [
                'user_id'    => $managerUser->user_id,
                'order_id'   => null,
                'type'       => 'low_stock',
                'title'      => 'Low Stock Alert',
                'message'    => '⚠️ Polyester Thread stock (12 kg) is below reorder threshold (20 kg).',
                'is_read'    => 0,
                'date_sent'  => now()->subMinutes(30),
            ],
        ];

        foreach ($notifs as $n) {
            // notif_id is auto-increment, date_sent required
            DB::table('notifications')->updateOrInsert(
                ['user_id' => $n['user_id'], 'title' => $n['title']],
                array_merge($n, ['created_at' => now(), 'updated_at' => now()])
            );
        }

        // ── SUMMARY ────────────────────────────────────────────────────────────
        $this->command->newLine();
        $this->command->info('═══════════════════════════════════════════');
        $this->command->info('✅ DefenseSeeder v11 complete');
        $this->command->info('');
        $this->command->info('Test accounts:');
        $this->command->line('  vfrbmanager@gmail.com  / VFRBmanager2026!  (manager)');
        $this->command->line('  roxanne@vfrbenterprise.com / VFRBRoxanne2026!  (manager)');
        $this->command->line('  vfrbstaff@gmail.com    / VFRBstaff2026!    (staff)');
        $this->command->line('  testcustomer@gmail.com / TestPass2026!     (customer)');
        $this->command->line('  otghospital@gmail.com  / OTGPass2026!      (customer)');
        $this->command->line('  pngschool@gmail.com    / PNGPass2026!      (customer)');
        $this->command->info('');
        $this->command->info('Orders seeded: pending, pattern, segregation, cutting,');
        $this->command->info('               sewing, qc, pressing, packing, completed');
        $this->command->info('');
        $this->command->info('Run: php artisan db:seed --class=DefenseSeeder');
        $this->command->info('═══════════════════════════════════════════');
    }
}