<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ── AUTOMATION: daily digest (low-stock notifications + auto-suggested
//    RFQs + delivery deadline reminders) ─────────────────────────────────
// Runs automatically every morning. Also callable on-demand via
// `php artisan vfrb:daily-digest` or POST /api/admin/automation/run-digest.
//
// NOTE (local/Laragon dev): Laravel's scheduler only fires if something is
// actually polling it — it does not run itself. On the real server this
// needs ONE real cron/Task Scheduler entry hitting `schedule:run` every
// minute; Laravel then decides internally whether `dailyAt('07:00')` is
// due. Without that single entry, this job will only ever run when
// triggered manually (via the command above or the "Run Now" button).
//   Linux/Railway cron:  * * * * * php artisan schedule:run >> /dev/null 2>&1
//   Windows (Laragon):   Task Scheduler → run every minute:
//                         php C:/laragon/www/vfrb-capstone/artisan schedule:run
Schedule::command('vfrb:daily-digest')->dailyAt('07:00');

// KPI snapshot for Dashboard sparklines — also fires lazily on dashboard
// load, so this is a backstop, not the only trigger. Runs late so the
// day's revenue/production figures are settled.
Schedule::command('vfrb:kpi-snapshot')->dailyAt('23:55');
