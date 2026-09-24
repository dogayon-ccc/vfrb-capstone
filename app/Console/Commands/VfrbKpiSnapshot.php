<?php
// app/Console/Commands/VfrbKpiSnapshot.php
// Captures today's KPI row for the Dashboard sparklines.
// Run manually: php artisan vfrb:kpi-snapshot
// Run on schedule: registered in routes/console.php
// Idempotent (KpiDailySnapshot::captureToday) — also fires lazily from
// AdminDashboardController on every dashboard load, so this command is a
// backstop for days the dashboard is never opened, not the only trigger.

namespace App\Console\Commands;

use App\Models\KpiDailySnapshot;
use Illuminate\Console\Command;

class VfrbKpiSnapshot extends Command
{
    protected $signature   = 'vfrb:kpi-snapshot';
    protected $description = 'Capture today\'s revenue/orders/production/stock-health snapshot for Dashboard sparklines';

    public function handle(): int
    {
        KpiDailySnapshot::captureToday();
        $this->info('KPI snapshot captured for ' . now()->toDateString());
        return self::SUCCESS;
    }
}
