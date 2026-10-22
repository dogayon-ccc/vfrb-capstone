<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes order, sales and transaction history; keeps users, roles, materials (with stock), inventory logs,
 * suppliers, designs, drafts, settings and audit logs. One transaction; JSON backup of every purged row first.
 * Runs for real only with --confirm=<driver>:<database> matching the current connection. --dry-run rolls back.
 */
class VfrbPurgeTransactions extends Command
{
    protected $signature = 'vfrb:purge-transactions
        {--dry-run : Delete inside a transaction, report, then roll back}
        {--confirm= : Required to commit: "<driver>:<database>" of the current connection, e.g. pgsql:postgres}';

    protected $description = 'Purge order, sales and transaction history (keeps accounts, materials and stock)';

    // Children before parents, so RESTRICT foreign keys never block a delete.
    private const PURGE = [
        'payment_receipts', 'invoices', 'sales_transactions', 'client_material_ledger',
        'delivery_tracking', 'daily_output_logs', 'order_production_tracking', 'qc_checklists',
        'measurements', 'order_messages', 'material_recommendations', 'production_incidents',
        'purchase_order_items', 'purchase_orders', 'rfq_responses', 'rfq_requests',
        'notifications', 'kpi_daily_snapshots', 'orders',
    ];

    // Cached figures built from orders or sales; cleared in the app cache and the database cache table.
    private const CACHE_KEYS = ['dashboard_stats', 'admin_reports_index', 'mrp_alerts'];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $conn = DB::connection();
        $target = $conn->getDriverName() . ':' . $conn->getDatabaseName();
        $this->line("Connection: {$target} (host " . config("database.connections.{$conn->getName()}.host") . ')');

        if (!$dry && $this->option('confirm') !== $target) {
            $this->error("Refusing to commit: pass --confirm={$target} (or use --dry-run).");
            return self::FAILURE;
        }

        $tables = array_values(array_filter(self::PURGE, fn ($t) => Schema::hasTable($t)));
        // App tables only: Supabase also has auth/storage schemas (with their own "users").
        $schema = $conn->getDriverName() === 'pgsql' ? 'public' : $conn->getDatabaseName();
        $all = collect(Schema::getTables($schema))->pluck('name')->values()->all();
        $before = $this->counts($all);

        // Backup every row that will be deleted, then read it back to prove the files are complete.
        $dir = 'backups/purge-' . now()->format('Ymd-His') . ($dry ? '-dryrun' : '');
        foreach ($tables as $t) {
            if (!Storage::disk('local')->put("{$dir}/{$t}.json", DB::table($t)->get()->toJson())) {
                $this->error("Backup failed for {$t}; nothing deleted.");
                return self::FAILURE;
            }
            $back = count(json_decode(Storage::disk('local')->get("{$dir}/{$t}.json"), true));
            if ($back !== $before[$t]) {
                $this->error("Backup check failed for {$t}: {$back} rows in file, {$before[$t]} in table. Nothing deleted.");
                return self::FAILURE;
            }
        }
        Storage::disk('local')->put("{$dir}/manifest.json", json_encode(['target' => $target, 'taken_at' => now()->toIso8601String(), 'rows' => array_intersect_key($before, array_flip($tables))], JSON_PRETTY_PRINT));
        $this->line("Backup: storage/app/private/{$dir} (" . array_sum(array_intersect_key($before, array_flip($tables))) . ' rows, verified)');

        DB::beginTransaction();
        try {
            foreach ($tables as $t) {
                $this->line(sprintf('  delete %5d  %s', DB::table($t)->delete(), $t));
            }

            // Nothing kept may still point at a deleted order.
            $dangling = [];
            foreach (array_diff($all, $tables) as $t) {
                if (Schema::hasColumn($t, 'order_id')) {
                    $n = DB::table($t)->whereNotNull('order_id')->count();
                    if ($n) $dangling["{$t}.order_id"] = $n;
                }
            }
            if (Schema::hasColumn('designs', 'source_order_id') && ($n = DB::table('designs')->whereNotNull('source_order_id')->count())) {
                $dangling['designs.source_order_id'] = $n;
            }
            if ($dangling) {
                throw new \RuntimeException('rows still reference deleted orders: ' . json_encode($dangling));
            }

            $after = $this->counts($all);
            $changed = array_filter(array_diff($all, $tables), fn ($t) => $before[$t] !== $after[$t] && $t !== 'cache');
            if ($changed) {
                throw new \RuntimeException('kept tables changed: ' . implode(', ', $changed));
            }

            // The deployed app keeps its cache in the database: drop cached order/sales figures there too.
            if (Schema::hasTable('cache')) {
                $n = DB::table('cache')->where(function ($q) {
                    foreach (self::CACHE_KEYS as $k) $q->orWhere('key', 'like', "%{$k}");
                    $q->orWhere('key', 'like', '%kpi_snapshot_%');
                })->delete();
                $this->line(sprintf('  delete %5d  cache entries (dashboard, reports, MRP, KPI snapshot flags)', $n));
            }

            $this->line('Rows: ' . array_sum($before) . ' -> ' . array_sum($after) . '; kept tables unchanged; no references to deleted orders.');

            if ($dry) {
                DB::rollBack();
                $this->warn('DRY RUN: rolled back, nothing deleted.');
                return self::SUCCESS;
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Rolled back, nothing deleted: ' . $e->getMessage());
            return self::FAILURE;
        }

        foreach (self::CACHE_KEYS as $k) Cache::forget($k);
        $this->info('Committed.');

        return self::SUCCESS;
    }

    private function counts(array $tables): array
    {
        return collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
    }
}
