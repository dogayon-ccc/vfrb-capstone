<?php

namespace App\Support;

use App\Models\NotificationPreference;
use App\Rules\PhMobile;
use App\Services\Sms\HttpSmsDriver;
use App\Services\Sms\LogSmsDriver;
use App\Services\Sms\SmsDriver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use function Illuminate\Support\defer;

// Text alerts, sent after the response like EmailNotify. Only when SMS_ENABLED is on, the user opted in, and their number is a
// valid PH mobile. Every attempt is logged with the event, user and a masked number; never the token or the message body.
final class SmsNotify
{
    public const EVENTS = ['order_submitted', 'status_changed', 'new_message', 'low_stock'];

    public static function live(): bool
    {
        return (bool) config('services.sms.enabled');
    }

    public static function users(iterable $userIds, string $event, string $message): void
    {
        $ids = collect($userIds)->filter()->unique()->values()->all();
        if (!$ids || !self::live()) {
            return;
        }
        $message = mb_substr(trim(preg_replace('/\s+/u', ' ', $message)), 0, 300);
        defer(fn () => self::sendNow($ids, $event, $message));
    }

    // Order status text to the customer, only when the label they see changes (internal stage moves stay silent).
    public static function orderStatus(int $orderId, int $customerId, string $from, string $to): void
    {
        if (OrderStatusLabel::changes($from, $to)) {
            self::users([$customerId], 'status_changed', "VFRB: Your order #{$orderId} is now " . OrderStatusLabel::for($to) . '.');
        }
    }

    // Synchronous core of users(); public for tests.
    public static function sendNow(array $ids, string $event, string $message): void
    {
        $driver = self::driver();
        $optedIn = NotificationPreference::whereIn('user_id', $ids)->where('sms_enabled', true)->pluck('user_id')->all();
        $numbers = DB::table('users')->whereIn('user_id', $optedIn)->pluck('contact_number', 'user_id');

        foreach ($ids as $id) {
            $ctx = ['event' => $event, 'user_id' => $id, 'driver' => $driver->name()];
            $to  = self::e164($numbers[$id] ?? null);
            if (!in_array($id, $optedIn) || !$to) {
                Log::info('sms.skipped', $ctx + ['reason' => in_array($id, $optedIn) ? 'no valid PH mobile' : 'not opted in']);
                continue;
            }
            try {
                $driver->send($to, $message);
                Log::info('sms.sent', $ctx + ['to' => self::mask($to)]);
            } catch (\Throwable $e) {
                Log::warning('sms.failed', $ctx + ['to' => self::mask($to), 'error' => $e->getMessage()]);
            }
        }
    }

    // 09XXXXXXXXX / +639XXXXXXXXX (spaces, dashes allowed) -> +639XXXXXXXXX; null when not a PH mobile.
    public static function e164(?string $raw): ?string
    {
        $v = PhMobile::normalize($raw);
        if ($v === null || !preg_match('/^(09\d{9}|\+639\d{9})$/', $v)) {
            return null;
        }
        return str_starts_with($v, '+') ? $v : '+63' . substr($v, 1);
    }

    public static function mask(string $e164): string
    {
        return substr($e164, 0, 6) . '****' . substr($e164, -3);
    }

    // Why this user cannot turn text alerts on, or null when they can.
    public static function optInError(?string $contactNumber): ?string
    {
        if (!self::live()) {
            return 'Text alerts are not switched on for VFRB yet.';
        }
        return self::e164($contactNumber) ? null : 'Add a PH mobile number (09XX XXX XXXX) to your profile before turning on text alerts.';
    }

    private static function driver(): SmsDriver
    {
        if (app()->bound(SmsDriver::class)) {
            return app(SmsDriver::class);
        }
        return config('services.sms.driver') === 'http' ? new HttpSmsDriver() : new LogSmsDriver();
    }
}
