<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

// Test fixture for CI only: catalogue codes, names, categories and units. Stock and cost are placeholders, not VFRB figures.
class CiMaterialsSeeder extends Seeder
{
    public function run(): void
    {
        if (DB::connection()->getDatabaseName() !== 'vfrb_test') {
            $this->command?->error('CiMaterialsSeeder only runs on vfrb_test.');

            return;
        }

        $now = now();
        foreach ([
            [1, 'MAT-FAB-001', 'Cotton-Poly Blend Fabric (White)', 'Fabric', 'yard'],
            [2, 'MAT-FAB-002', 'Cotton-Poly Blend Fabric (Navy)', 'Fabric', 'yard'],
            [3, 'MAT-FAB-003', 'Twill Fabric (Khaki)', 'Fabric', 'yard'],
            [4, 'MAT-THR-001', 'Polyester Thread (White)', 'Thread', 'meter'],
            [5, 'MAT-THR-002', 'Polyester Thread (Black)', 'Thread', 'meter'],
            [6, 'MAT-ELA-001', 'Elastic Band (1 inch)', 'Elastic', 'meter'],
            [7, 'MAT-ACC-001', 'Plastic Buttons (Medium)', 'Accessories', 'piece'],
            [8, 'MAT-ACC-002', 'YKK Zipper (7 inch)', 'Accessories', 'piece'],
            [9, 'MAT-TRI-001', 'Bias Tape / Piping', 'Trims', 'yard'],
            [10, 'MAT-TRI-002', 'Interfacing / Lining Fabric', 'Trims', 'yard'],
            [11, 'MAT-FAB-004', 'Airstretch Fabric', 'Fabric', 'yards'],
            [12, 'MAT-FAB-005', 'Cotton Fabric', 'Fabric', 'yards'],
            [13, 'MAT-FAB-006', 'Polyester-Cotton Blend Fabric', 'Fabric', 'yards'],
            [14, 'MAT-THR-003', 'Polyester Thread', 'Thread', 'kg'],
            [15, 'MAT-THR-004', 'Cotton Thread', 'Thread', 'kg'],
            [16, 'MAT-ELA-002', 'Elastic Band (1-inch)', 'Elastic', 'meters'],
            [17, 'MAT-TRI-003', 'V-Neck Attachment Strip', 'Trim', 'pieces'],
            [18, 'MAT-ACC-003', 'Logo Patch', 'Accessories', 'pieces'],
            [19, 'MAT-ACC-004', 'Buttons (Set)', 'Accessories', 'sets'],
            [20, 'MAT-ACC-005', 'Zipper (30cm)', 'Accessories', 'pieces'],
            [21, 'MAT-FAB-007', 'Satin Lining', 'Fabric', 'yards'],
            [22, 'MAT-FAB-008', 'Interlining', 'Fabric', 'yards'],
        ] as [$id, $code, $name, $category, $unit]) {
            DB::table('materials')->updateOrInsert(['material_id' => $id], [
                'material_code' => $code, 'material_name' => $name, 'category' => $category, 'unit' => $unit,
                'quantity_in_stock' => 500, 'reorder_threshold' => 50, 'unit_cost' => 100, 'ai_eligible' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }
}
