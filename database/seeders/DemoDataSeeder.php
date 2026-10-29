<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

// Defense demo data for the local vfrb_demo copy only. Idempotent: rows are keyed on demo emails, codes and po_reference.
// Run: DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=vfrb_demo ... php artisan db:seed --class=DemoDataSeeder
class DemoDataSeeder extends Seeder
{
    public const PASSWORD = 'VfrbDemo2026!';

    private const STAGES = ['pattern', 'segregation', 'cutting', 'sewing', 'qc', 'pressing', 'packing'];

    private const CUSTOMERS = [
        ['Ana Villanueva',  'ana.villanueva',  'school',       'Sta. Clara Learning Center'],
        ['Bea Ramos',       'bea.ramos',       'medical',      'Mabini Family Clinic'],
        ['Carlo Mendoza',   'carlo.mendoza',   'corporate',    'Northpoint Logistics Inc.'],
        ['Dina Castillo',   'dina.castillo',   'organization', 'Bayanihan Volunteers Assoc.'],
        ['Emil Navarro',    'emil.navarro',    'government',   'Barangay San Roque Hall'],
        ['Faye Domingo',    'faye.domingo',    'individual',   null],
    ];

    // [customer index, status, garment, sleeve, colour hex, qty]; covers all 12 statuses and all 7 stages.
    private const ORDERS = [
        [0, 'pending',      'Polo Shirt',      'Long',  '#1B2A4A', 120],
        [0, 'pattern',      'Mandarin Blouse', 'Short', '#FFFFFF', 80],
        [0, 'completed',    'Polo Shirt',      'Short', '#800000', 150],
        [1, 'pending',      'Scrub Top',       'Short', '#028090', 60],
        [1, 'segregation',  'Scrub Top',       'Short', '#1B2A4A', 90],
        [1, 'for_delivery', 'Scrub Top',       'Short', '#5B8C5A', 75],
        [2, 'confirmed',    'Barong Shirt',    'Long',  '#F4EBD9', 50],
        [2, 'cutting',      'Polo Shirt',      'Short', '#1B2A4A', 200],
        [2, 'cancelled',    'Polo Shirt',      'Long',  '#3E4A55', 100],
        [3, 'sewing',       'Polo Shirt',      'Short', '#C4313B', 140],
        [3, 'qc',           'Mandarin Blouse', 'Long',  '#1B2A4A', 70],
        [3, 'pressing',     'Barong Shirt',    'Short', '#F4EBD9', 40],
        [4, 'packing',      'Polo Shirt',      'Short', '#027A8B', 110],
        [4, 'sewing',       'Barong Shirt',    'Long',  '#F4EBD9', 60],
        [4, 'confirmed',    'Scrub Top',       'Short', '#2F6FDB', 100],
        [5, 'pending',      'Mandarin Blouse', 'Short', '#6D3FD6', 30],
        [5, 'cutting',      'Scrub Top',       'Short', '#0F8F80', 45],
        [5, 'completed',    'Barong Shirt',    'Short', '#F4EBD9', 35],
    ];

    private array $roleIds = [];

    public function run(): void
    {
        $this->guardDatabase();
        $this->roleIds = DB::table('roles')->pluck('id', 'name')->all();

        DB::transaction(function () {
            $this->removeQaOrders();
            $managerId = $this->user('Demo Manager', 'demo-manager', 'manager');
            $stationId = $this->user('Demo Sewing Station', 'demo-station', 'staff', ['assigned_stage' => 'sewing']);
            $customerIds = array_map(fn ($c, $n) => $this->user($c[0], $c[1], 'customer', [
                'client_type' => $c[2], 'organization_name' => $c[3], 'contact_number' => '0917 555 01'.(20 + $n),
            ]), self::CUSTOMERS, array_keys(self::CUSTOMERS));

            foreach (self::ORDERS as $i => $spec) {
                $this->order($i, $spec, $customerIds[$spec[0]], $managerId, $stationId);
            }

            $this->materialsAndPurchasing($managerId);
            $this->pendingAccessRequest();
        });

        foreach (['materials_list', 'dashboard_stats', 'mrp_alerts', 'admin_reports_index'] as $key) {
            Cache::forget($key);
        }
        $this->command?->info('Demo data ready. Password for every demo login: '.self::PASSWORD);
    }

    private function guardDatabase(): void
    {
        $name = config('database.default');
        $db = config("database.connections.$name.database");
        $this->command?->info("Connection: $name / database: $db / host: ".config("database.connections.$name.host"));
        if ($db !== 'vfrb_demo') {
            throw new RuntimeException("DemoDataSeeder only runs on vfrb_demo (got '$db'). Nothing was written.");
        }
    }

    // Orders #149-153 came over from vfrb_qa; their FKs mix CASCADE and SET NULL, so children are deleted explicitly.
    private function removeQaOrders(): void
    {
        $ids = DB::table('orders')->join('users', 'users.user_id', '=', 'orders.user_id')
            ->where('users.email', 'qa-walk-1021@mailinator.com')->whereBetween('orders.order_id', [149, 153])->pluck('orders.order_id');
        if ($ids->isEmpty()) {
            return;
        }
        foreach (['payment_receipts', 'invoices', 'sales_transactions', 'client_material_ledger', 'material_recommendations', 'measurements',
            'notifications', 'order_messages', 'order_production_tracking', 'daily_output_logs', 'qc_checklists', 'delivery_tracking'] as $t) {
            DB::table($t)->whereIn('order_id', $ids)->delete();
        }
        DB::table('orders')->whereIn('order_id', $ids)->update(['design_id' => null]);
        DB::table('designs')->whereIn('source_order_id', $ids)->delete();
        DB::table('orders')->whereIn('order_id', $ids)->delete();
        $this->command?->info('Removed QA orders: #'.$ids->implode(', #'));
    }

    private function user(string $name, string $handle, string $role, array $extra = []): int
    {
        $email = "$handle@example.test";
        DB::table('users')->updateOrInsert(['email' => $email], $extra + [
            'name' => $name, 'username' => str_replace('.', '_', $handle), 'password' => Hash::make(self::PASSWORD),
            'email_verified_at' => now(), 'created_at' => now()->subDays(30), 'updated_at' => now(),
        ]);
        $id = (int) DB::table('users')->where('email', $email)->value('user_id');
        DB::table('model_has_roles')->where(['model_type' => 'App\\Models\\User', 'model_id' => $id])->delete();
        DB::table('model_has_roles')->insert(['role_id' => $this->roleIds[$role], 'model_type' => 'App\\Models\\User', 'model_id' => $id]);

        return $id;
    }

    private function order(int $i, array $spec, int $userId, int $managerId, int $stationId): void
    {
        [, $status, $garment, $sleeve, $hex, $qty] = $spec;
        $ref = sprintf('DEMO-%02d', $i + 1);
        $created = now()->subDays(20 - $i % 6)->startOfDay()->addHours(9);
        $agreed = ! in_array($status, ['pending', 'cancelled'], true);
        $proposed = $i === 0;
        $config = ['name' => "$garment for ".self::CUSTOMERS[$spec[0]][0], 'garment' => $garment, 'sleeve' => $sleeve,
            'fit' => $garment === 'Barong Shirt' ? 'male' : 'female', 'colors' => ['body' => $hex], 'patterns' => []];

        DB::table('orders')->updateOrInsert(['user_id' => $userId, 'po_reference' => $ref], [
            'status' => $status, 'order_type' => $qty >= 100 ? 'bulk' : 'direct', 'payment_terms' => 'down',
            'quantity_ordered' => $qty, 'sizing_type' => 'standard', 'garment_type' => $garment, 'sleeve_type' => $sleeve,
            'color' => $hex, 'fabric_type' => $garment === 'Scrub Top' ? 'Airstretch' : 'Cotton', 'studio_config' => json_encode($config),
            'target_delivery_date' => now()->addDays(25 + $i)->toDateString(),
            'promised_date' => $agreed || $proposed ? now()->addDays(30 + $i)->toDateString() : null,
            'proposal_status' => $agreed ? 'accepted' : ($proposed ? 'pending' : null),
            'proposal_note' => $agreed || $proposed ? 'Fabric is in stock in your chosen colour. The date allows for one fitting sample before the full run.' : null,
            'agreed_total' => $agreed || $proposed ? $qty * 380 : null,
            'qc_required' => 1, 'qc_passed_at' => $this->stageIndex($status) > 4 ? now()->subDays(2) : null,
            'delivery_address' => self::CUSTOMERS[$spec[0]][3] ? self::CUSTOMERS[$spec[0]][3].', Quezon City' : 'Quezon City',
            'notes' => $status === 'cancelled' ? 'Cancelled at the customer\'s request before production.' : null,
            'created_at' => $created, 'updated_at' => now(),
        ]);
        $orderId = (int) DB::table('orders')->where(['user_id' => $userId, 'po_reference' => $ref])->value('order_id');

        $this->sizes($orderId, $userId, $qty);
        $this->design($orderId, $garment, $sleeve, $hex, $config, $created);
        $this->production($orderId, $status, $qty, $managerId, $stationId);
        $this->delivery($orderId, $status, $qty, $managerId);
        $this->messages($orderId, $i, $status, $userId, $managerId);
    }

    private function sizes(int $orderId, int $userId, int $qty): void
    {
        DB::table('measurements')->where('order_id', $orderId)->delete();
        foreach ($this->split($qty) as $label => $n) {
            DB::table('measurements')->insert(['user_id' => $userId, 'order_id' => $orderId, 'type' => 'standard',
                'size_label' => $label, 'qty' => $n, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    // A customer's saved design is listed through source_order_id; a null source would make it a template for everyone.
    private function design(int $orderId, string $garment, string $sleeve, string $hex, array $config, $created): void
    {
        $name = "$garment ($sleeve) · Order #$orderId";
        DB::table('designs')->updateOrInsert(['source_order_id' => $orderId], [
            'design_name' => $name, 'garment_type' => $garment, 'sleeve_type' => strtolower($sleeve), 'color' => $hex,
            'is_active' => 1, 'showcase_status' => 'none', 'custom_builder_config' => json_encode($config),
            'created_at' => $created, 'updated_at' => now(),
        ]);
        DB::table('orders')->where('order_id', $orderId)
            ->update(['design_id' => DB::table('designs')->where('source_order_id', $orderId)->value('design_id')]);
    }

    private function stageIndex(string $status): int
    {
        $k = array_search($status, self::STAGES, true);

        return $k !== false ? $k : (in_array($status, ['for_delivery', 'completed'], true) ? 7 : -1);
    }

    // Finished stages are logged two days each, ending with the current stage (partly done) today; inside the last 14 days.
    private function production(int $orderId, string $status, int $qty, int $managerId, int $stationId): void
    {
        foreach (['order_production_tracking', 'daily_output_logs', 'qc_checklists'] as $t) {
            DB::table($t)->where('order_id', $orderId)->delete();
        }
        $current = $this->stageIndex($status);
        if ($current < 0) {
            return;
        }
        $last = min($current, 6);
        foreach (self::STAGES as $j => $stage) {
            if ($j > $last) {
                break;
            }
            $done = $j < $current ? $qty : (int) round($qty * (0.35 + 0.1 * ($orderId % 4)));
            $by = $stage === 'sewing' ? $stationId : $managerId;
            $daysAgo = 2 * ($last - $j);
            $first = (int) ceil($done / 2);
            foreach ([[$daysAgo + 1, $first], [$daysAgo, $done - $first]] as [$ago, $n]) {
                if ($n > 0) {
                    $this->outputLog($orderId, $stage, $by, $n, $ago);
                }
            }
            DB::table('order_production_tracking')->insert(['order_id' => $orderId, 'stage' => $stage, 'qty_target' => $qty,
                'qty_completed' => $done, 'updated_by' => $by, 'created_at' => now()->subDays($daysAgo + 1), 'updated_at' => now()->subDays($daysAgo)]);
        }
        if ($current >= 4) {
            $this->qcCheck($orderId, $managerId, $current > 4, $qty);
        }
    }

    private function outputLog(int $orderId, string $stage, int $by, int $total, int $daysAgo): void
    {
        $sizes = $this->split($total);
        $defects = $stage === 'sewing' ? $total % 3 : 0;
        DB::table('daily_output_logs')->insert([
            'order_id' => $orderId, 'stage' => $stage, 'logged_by' => $by,
            'qty_s' => $sizes['S'], 'qty_m' => $sizes['M'], 'qty_l' => $sizes['L'],
            'defect_count' => $defects, 'defect_size' => $defects ? 'M' : null, 'defect_notes' => $defects ? 'Uneven topstitch on the collar; resewn.' : null,
            'log_date' => now()->subDays($daysAgo)->toDateString(), 'created_at' => now()->subDays($daysAgo), 'updated_at' => now()->subDays($daysAgo),
        ]);
    }

    private function qcCheck(int $orderId, int $checkerId, bool $passed, int $qty): void
    {
        $sample = min(20, $qty);
        $failed = $passed ? 0 : 4;
        DB::table('qc_checklists')->insert([
            'order_id' => $orderId, 'checked_by' => $checkerId, 'chest_cm' => 96.5, 'length_cm' => 68.0, 'sleeve_cm' => 21.5,
            'shoulder_cm' => 42.0, 'stitching_ok' => (int) $passed, 'color_ok' => 1, 'size_ok' => 1, 'label_ok' => 1,
            'finish_ok' => (int) $passed, 'button_ok' => 1, 'passed' => (int) $passed,
            'notes' => $passed ? 'Sample within tolerance.' : 'Loose threads at hem and skipped stitches on 4 pieces; returned for repair.',
            'items_checked' => $sample, 'items_passed' => $sample - $failed, 'items_failed' => $failed,
            'checked_at' => now()->subDays(1), 'created_at' => now()->subDays(1), 'updated_at' => now()->subDays(1),
        ]);
    }

    private function delivery(int $orderId, string $status, int $qty, int $managerId): void
    {
        DB::table('delivery_tracking')->where('order_id', $orderId)->delete();
        $state = ['packing' => 'preparing', 'for_delivery' => 'dispatched', 'completed' => 'delivered'][$status] ?? null;
        if (! $state) {
            return;
        }
        DB::table('delivery_tracking')->insert([
            'order_id' => $orderId, 'delivery_method' => 'vfrb_deliver', 'delivery_status' => $state,
            'delivered_qty' => $state === 'delivered' ? $qty : 0, 'estimated_delivery_date' => now()->addDays(3)->toDateString(),
            'actual_delivery_date' => $state === 'delivered' ? now()->subDays(1)->toDateString() : null,
            'updated_by' => $managerId, 'created_at' => now()->subDays(2), 'updated_at' => now(),
        ]);
    }

    // Customer-visible thread: high-level status only, no prices, formulas or stage names.
    private function messages(int $orderId, int $i, string $status, int $customerId, int $managerId): void
    {
        DB::table('order_messages')->where('order_id', $orderId)->delete();
        $thread = match (true) {
            $i === 0 => [[$managerId, 'We sent a proposal with a delivery date for your polos. Please review and accept it when you are ready.', 1]],
            $status === 'sewing' && $i === 9 => [
                [$managerId, 'Good news: your order is in production and on schedule.', 1],
                [$customerId, 'Thank you! Can our logo go slightly higher on the left chest?', 0],
            ],
            $status === 'for_delivery' => [
                [$managerId, 'Your order is ready and on its way. Our driver will call before arriving.', 1],
                [$customerId, 'Noted, someone will be at the clinic front desk until 5 PM.', 1],
            ],
            $status === 'cancelled' => [[$managerId, 'As requested, this order has been cancelled. Message us anytime to reorder.', 1]],
            $status === 'completed' => [[$customerId, 'Received everything in good condition. Thank you, VFRB!', 1]],
            default => [],
        };
        foreach ($thread as $n => [$sender, $body, $read]) {
            $at = now()->subHours(6 * (count($thread) - $n));
            DB::table('order_messages')->insert(['order_id' => $orderId, 'sender_id' => $sender, 'body' => $body,
                'is_read' => $read, 'sent_at' => $at, 'created_at' => $at, 'updated_at' => $at]);
        }
    }

    private function materialsAndPurchasing(int $managerId): void
    {
        foreach ([
            ['DEMO-LS1', 'Bias Binding (Navy)', 'Trims', 'yards', 8, 25, 18],
            ['DEMO-LS2', 'Snap Buttons (Silver)', 'Accessories', 'pieces', 40, 150, 2],
        ] as [$code, $name, $cat, $unit, $stock, $min, $cost]) {
            DB::table('materials')->updateOrInsert(['material_code' => $code], ['material_name' => $name, 'category' => $cat,
                'ownership' => 'vfrb', 'unit' => $unit, 'quantity_in_stock' => $stock, 'reorder_threshold' => $min,
                'unit_cost' => $cost, 'created_at' => now(), 'updated_at' => now()]);
        }

        foreach ([
            ['Luzon Weave Textiles', 'Marites Cruz', 'sales@luzonweave.example.test', 'Cotton, poly-cotton and airstretch fabric', 7],
            ['Tahi Notions Supply', 'Ramon Dela Paz', 'orders@tahinotions.example.test', 'Thread, buttons, zippers, binding', 3],
        ] as [$name, $person, $email, $supplies, $lead]) {
            DB::table('suppliers')->updateOrInsert(['email' => $email], ['supplier_name' => $name, 'contact_person' => $person,
                'phone' => '02 8555 01'.$lead.'0', 'address' => 'Quezon City', 'supplier_type' => 'direct', 'materials_supplied' => $supplies,
                'payment_terms_with_supplier' => 'net_30', 'lead_time_days' => $lead, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }

        $supplierId = DB::table('suppliers')->where('email', 'orders@tahinotions.example.test')->value('supplier_id');
        $items = DB::table('materials')->whereIn('material_code', ['DEMO-LS1', 'DEMO-LS2'])->get(['material_id', 'unit_cost', 'reorder_threshold']);
        $total = $items->sum(fn ($m) => $m->reorder_threshold * 2 * $m->unit_cost);
        DB::table('purchase_orders')->updateOrInsert(['po_number' => 'PO-DEMO-0001'], ['supplier_id' => $supplierId, 'created_by' => $managerId,
            'status' => 'sent', 'total_amount' => $total, 'expected_delivery_date' => now()->addDays(3)->toDateString(),
            'notes' => 'Restock for low trims.', 'created_at' => now()->subDay(), 'updated_at' => now()]);
        $poId = DB::table('purchase_orders')->where('po_number', 'PO-DEMO-0001')->value('po_id');
        DB::table('purchase_order_items')->where('po_id', $poId)->delete();
        foreach ($items as $m) {
            DB::table('purchase_order_items')->insert(['po_id' => $poId, 'material_id' => $m->material_id,
                'qty' => $m->reorder_threshold * 2, 'unit_cost' => $m->unit_cost, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    // Unlinked and not created by an admin: this is how the Requests list identifies a pending "Request access".
    private function pendingAccessRequest(): void
    {
        DB::table('customer_contacts')->updateOrInsert(['email' => 'gina.morales@example.test'], [
            'name' => 'Gina Morales', 'mobile' => '0918 555 0142', 'organization_name' => 'San Isidro Parish School',
            'user_id' => null, 'created_by' => null, 'created_at' => now()->subHours(5), 'updated_at' => now(),
        ]);
    }

    private function split(int $qty): array
    {
        $s = intdiv($qty, 3);
        $m = intdiv($qty - $s, 2);

        return ['S' => $s, 'M' => $m, 'L' => $qty - $s - $m];
    }
}
