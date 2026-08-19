<?php
// database/seeders/MaterialUsageRateSeeder.php
//
// Populates material_usage_rates — previously completely empty, meaning
// every AI material recommendation returned "Not yet configured" for
// estimated quantities regardless of the BUG-008 parse fix.
//
// SCOPE — what this seeder does and does NOT do:
//
//   ✅ FABRIC: 3.5 yards/piece, flat rate, per the Apr 30 2026 Ma'am Fe
//      interview transcript. Applied to every material where
//      materials.category = 'Fabric', across all 16 real garment_type
//      values used by OrderWizard.jsx (the live order-creation form —
//      NOT the legacy snake_case seed values like 'polo_shirt', which
//      only exist in old test data and are never produced by the current
//      UI). This is the one part of the formula that's a genuine flat
//      multiplier, so it's the only part seeded with full confidence.
//
//   ⚠️ ELASTIC: APPROXIMATION ONLY, not a real per-piece formula. The
//      interview formula is "waistline − 3 inches" — a PER-PIECE value
//      depending on each customer's actual measurement, not a constant
//      that scales with quantity_ordered. The current deterministic
//      engine (computeDeterministicQty() in AIController.php) only
//      supports qty_per_unit × quantity_ordered, which cannot represent
//      "depends on this specific person's waist." This seeder uses the
//      documented M-size baseline (96cm) as a stand-in:
//        96cm − 3in (7.62cm) = 88.38cm ≈ 0.88 meters/piece
//      This will UNDER- or OVER-estimate for every non-M size and every
//      custom-sized order. Only applied to the 6 garment types that
//      actually need waist elastic (bottoms). Flag this to whoever owns
//      product decisions before trusting these numbers for real ordering.
//
//   ❌ THREAD: DELIBERATELY NOT SEEDED. The interview formula says 350
//      meters of thread per piece, but every thread material in the
//      materials table is stocked in kilograms (kg/kgs), not meters.
//      Converting meters→kg requires a weight-per-length figure (denier/
//      tex) for the actual thread being used, which isn't documented
//      anywhere in the codebase, schema comments, or interview
//      transcript. Per AIController.php's own rule ("never guess a
//      business figure"), no conversion factor is invented here. Get
//      the real number from Ma'am Fe/Roxanne, or add a meters-tracked
//      thread material, before adding these rows.

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MaterialUsageRateSeeder extends Seeder
{
    // The 16 real garment_type values OrderWizard.jsx actually produces —
    // copied verbatim from GARMENT_SPECS in that file so this can never
    // drift out of sync with what the live form sends. Every one of these
    // needs body fabric, so all 16 get a fabric rate.
    private const ALL_GARMENT_TYPES = [
        'T-Shirt', 'Polo Shirt', 'School Uniform Top', 'School Uniform Bottom',
        'PE Uniform Top', 'PE Uniform Bottom', 'Blouse', 'Polo Barong',
        'Medical Scrubs Top', 'Medical Scrubs Bottom', 'Shorts', 'Pants',
        'Skirt', 'Jacket', 'Vest', 'Others',
    ];

    // Only these need waist elastic (GARMENT_SPECS needsWaist:true in the
    // frontend) — tops never get an elastic rate.
    private const WAIST_GARMENT_TYPES = [
        'School Uniform Bottom', 'PE Uniform Bottom', 'Medical Scrubs Bottom',
        'Shorts', 'Pants', 'Skirt',
    ];

    public function run(): void
    {
        $setBy = DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('roles.name', 'manager')
            ->orderBy('model_has_roles.model_id')
            ->value('model_has_roles.model_id');

        $now = now();

        // ── FABRIC: 3.5 yards/piece, every Fabric material, every garment type ──
        $fabricMaterials = DB::table('materials')->where('category', 'Fabric')->pluck('material_id');
        $fabricRows = [];
        foreach ($fabricMaterials as $materialId) {
            foreach (self::ALL_GARMENT_TYPES as $garmentType) {
                $fabricRows[] = [
                    'material_id'  => $materialId,
                    'garment_type' => $garmentType,
                    'qty_per_unit' => 3.5,
                    'unit'         => 'yards',
                    'set_by'       => $setBy,
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ];
            }
        }
        foreach (array_chunk($fabricRows, 200) as $chunk) {
            DB::table('material_usage_rates')->upsert(
                $chunk,
                ['material_id', 'garment_type'],
                ['qty_per_unit', 'unit', 'set_by', 'updated_at']
            );
        }

        // ── ELASTIC: 0.88 meters/piece, APPROXIMATION (M-size baseline), bottoms only ──
        $elasticMaterials = DB::table('materials')->where('category', 'Elastic')->pluck('material_id');
        $elasticRows = [];
        foreach ($elasticMaterials as $materialId) {
            foreach (self::WAIST_GARMENT_TYPES as $garmentType) {
                $elasticRows[] = [
                    'material_id'  => $materialId,
                    'garment_type' => $garmentType,
                    'qty_per_unit' => 0.88,
                    'unit'         => 'meters',
                    'set_by'       => $setBy,
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ];
            }
        }
        if ($elasticRows) {
            DB::table('material_usage_rates')->upsert(
                $elasticRows,
                ['material_id', 'garment_type'],
                ['qty_per_unit', 'unit', 'set_by', 'updated_at']
            );
        }

        $this->command->info(
            'Seeded ' . count($fabricRows) . ' fabric rates + ' . count($elasticRows) .
            ' elastic rates (APPROXIMATE — see class docblock). Thread NOT seeded — unit mismatch unresolved.'
        );
    }
}