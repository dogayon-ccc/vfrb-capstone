<?php

namespace App\Support;

use Illuminate\Support\Carbon;

// The daily jobs, read by routes/console.php (schedule:run) and the HTTP cron endpoint. Times are Manila time (BusinessDay).
// Every job here must be safe to run more than once a day; each dedupes itself.
final class ScheduledJobs
{
    public const DAILY = [
        'vfrb:daily-digest' => '07:00',
        'vfrb:kpi-snapshot' => '23:55',
    ];

    /** @return string[] commands whose time today has already passed */
    public static function due(?Carbon $now = null): array
    {
        $now ??= BusinessDay::now();

        return array_keys(array_filter(self::DAILY, fn (string $at) => $now->format('H:i') >= $at));
    }
}
