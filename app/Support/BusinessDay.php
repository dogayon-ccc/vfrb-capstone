<?php

namespace App\Support;

use Illuminate\Support\Carbon;

// "Today" for VFRB's business dates (overdue, deadlines, cron times), always Manila; timestamps written to rows keep app.timezone.
final class BusinessDay
{
    public const TIMEZONE = 'Asia/Manila';

    public static function now(): Carbon
    {
        return now(self::TIMEZONE);
    }
}
