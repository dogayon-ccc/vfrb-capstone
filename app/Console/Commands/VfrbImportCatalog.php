<?php

namespace App\Console\Commands;

use App\Models\Material;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * One-time load of database/seed-data/{suppliers,materials}_import.csv.
 * Refuses to run when either table already has rows. --dry-run rolls back.
 */
class VfrbImportCatalog extends Command
{
    protected $signature = 'vfrb:import-catalog {--dry-run : Print what would be written, then roll back}';

    protected $description = 'Import the supplier and material catalog from database/seed-data CSVs';

    /** CSV category -> category the app already uses. */
    private const CATEGORY_MAP = [
        'Fabric / Stretch Knit'   => 'Fabric',
        'Fabric / Woven'          => 'Fabric',
        'Fabric / Knit'           => 'Fabric',
        'Thread'                  => 'Thread',
        'Accessory / Elastic'     => 'Elastic',
        'Accessory'               => 'Accessories',
        'Accessory / Button'      => 'Accessories',
        'Accessory / Zipper'      => 'Accessories',
        'Accessory / Interlining' => 'Accessories',
        'Accessory / Knit Trim'   => 'Trims',
        'Label / Logo'            => 'Trims',
        'Label'                   => 'Trims',
        'Packaging'               => 'Accessories',
    ];

    private const UNIT_MAP = ['kgs' => 'kg'];

    /** Labels and packaging stay in inventory but are never offered to the AI. */
    private const NOT_AI_ELIGIBLE = ['Label / Logo', 'Label', 'Packaging'];

    /** OTG supplies fabric for subcontract orders; everyone else sells to VFRB directly. */
    private const SUBCONTRACT_SUPPLIERS = ['OTG (On The Go) Scrubs'];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $suppliers = $this->readCsv(database_path('seed-data/suppliers_import.csv'));
        $materials = $this->readCsv(database_path('seed-data/materials_import.csv'));

        $unmapped = array_unique(array_filter(array_map(
            fn ($r) => isset(self::CATEGORY_MAP[$r['category']]) ? null : $r['category'], $materials)));
        if ($unmapped) {
            $this->error('Unmapped categories: ' . implode(', ', $unmapped));
            return self::FAILURE;
        }

        $before = ['suppliers' => DB::table('suppliers')->count(), 'materials' => DB::table('materials')->count()];
        if (($before['suppliers'] || $before['materials']) && !$dry) {
            $this->error("Tables not empty (suppliers={$before['suppliers']}, materials={$before['materials']}). Import already ran; refusing.");
            return self::FAILURE;
        }

        if (!$dry) {
            $stamp = now()->format('Ymd-His');
            foreach (['suppliers', 'materials'] as $t) {
                $path = "backups/{$t}-{$stamp}.json";
                if (!Storage::disk('local')->put($path, DB::table($t)->get()->toJson(JSON_PRETTY_PRINT))) {
                    $this->error("Backup failed for {$t}; nothing imported.");
                    return self::FAILURE;
                }
                $this->line("Backup: storage/app/private/{$path} ({$before[$t]} rows)");
            }
        }

        DB::beginTransaction();
        try {
            $this->importSuppliers($suppliers, $materials);
            $this->importMaterials($materials);

            $after = ['suppliers' => DB::table('suppliers')->count(), 'materials' => DB::table('materials')->count()];
            $this->newLine();
            $this->line("suppliers: {$before['suppliers']} -> {$after['suppliers']}");
            $this->line("materials: {$before['materials']} -> {$after['materials']}");

            if ($dry) {
                DB::rollBack();
                $this->warn('DRY RUN: rolled back, nothing written.');
            } else {
                DB::commit();
                $this->info('Committed.');
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Rolled back: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function importSuppliers(array $suppliers, array $materials): void
    {
        $rows = [];
        foreach ($suppliers as $s) {
            // raw = "Name | location | contact/brand"
            $parts   = array_map('trim', explode('|', $s['raw']));
            $extra   = array_slice($parts, 1);
            $address = $extra && !str_contains($extra[0], 'brand') ? array_shift($extra) : null;
            $notes   = array_filter([
                $s['website'] ? 'Website: ' . $s['website'] : null,
                ...array_filter($extra, fn ($x) => $x !== $s['email'] && $x !== $s['website']),
            ]);
            $supplied = array_column(array_filter($materials, fn ($m) => $m['supplier'] === $s['name']), 'material_name');

            $rows[] = [
                'supplier_name'               => $s['name'],
                'email'                       => $s['email'] ?: null,
                'address'                     => $address,
                'supplier_type'               => in_array($s['name'], self::SUBCONTRACT_SUPPLIERS, true) ? 'subcontract' : 'direct',
                'materials_supplied'          => implode('; ', $supplied) ?: null,
                'payment_terms_with_supplier' => 'not_specified',
                'is_active'                   => 1,
                'notes'                       => implode(' | ', $notes) ?: null,
                'created_at'                  => now(),
                'updated_at'                  => now(),
            ];
        }
        DB::table('suppliers')->insert($rows);

        $this->table(['supplier_name', 'type', 'email', 'address', 'notes', '#materials'], array_map(fn ($r) => [
            $r['supplier_name'], $r['supplier_type'], $r['email'] ?? '-', $r['address'] ?? '-', $r['notes'] ?? '-',
            $r['materials_supplied'] ? count(explode('; ', $r['materials_supplied'])) : 0,
        ], $rows));
    }

    private function importMaterials(array $materials): void
    {
        $out = [];
        foreach ($materials as $m) {
            $category = self::CATEGORY_MAP[$m['category']];
            $unit     = self::UNIT_MAP[$m['unit']] ?? $m['unit'];
            $row = [
                'material_code'     => Material::nextCode($category),
                'material_name'     => $m['material_name'],
                'category'          => $category,
                'unit'              => $unit,
                'quantity_in_stock' => $this->num($m['quantity_in_stock']),
                'reorder_threshold' => $this->num($m['reorder_threshold']),
                'unit_cost'         => $this->num($m['unit_cost_midpoint']),
                'ai_eligible'       => in_array($m['category'], self::NOT_AI_ELIGIBLE, true) ? 0 : 1,
                'created_at'        => now(),
                'updated_at'        => now(),
            ];
            DB::table('materials')->insert($row); // one by one so nextCode() sees prior rows
            $out[] = [$m['sheet_id'], $row['material_code'], $row['material_name'], "{$m['category']} -> {$category}",
                $unit, $row['quantity_in_stock'], $row['reorder_threshold'], $row['unit_cost'], $row['ai_eligible'] ? 'yes' : 'no'];
        }
        $this->table(['sheet', 'code', 'name', 'category', 'unit', 'stock', 'reorder', 'unit_cost', 'AI'], $out);
    }

    private function readCsv(string $path): array
    {
        $fh = fopen($path, 'r');
        $head = fgetcsv($fh);
        $head[0] = preg_replace('/^\xEF\xBB\xBF/', '', $head[0]);
        $rows = [];
        while (($r = fgetcsv($fh)) !== false) {
            if ($r === [null]) { continue; }
            $rows[] = array_map('trim', array_combine($head, $r));
        }
        fclose($fh);
        return $rows;
    }

    private function num(string $v): float
    {
        return (float) str_replace(',', '', $v);
    }
}
