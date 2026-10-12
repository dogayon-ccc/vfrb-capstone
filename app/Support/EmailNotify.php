<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

use function Illuminate\Support\defer;

// Emails the same people an in-app notification went to, after the response is sent. Best-effort: a mail failure is logged and
// never fails the business action. Each user's email preference is applied by the notification's via().
final class EmailNotify
{
    public static function users(iterable $userIds, Notification $notification): void
    {
        $ids = collect($userIds)->filter()->unique()->values()->all();
        if (!$ids) {
            return;
        }
        defer(function () use ($ids, $notification) {
            foreach (User::whereIn('user_id', $ids)->get() as $user) {
                try {
                    $user->notify($notification);
                } catch (\Throwable $e) {
                    Log::warning('Email notification failed for user #' . $user->user_id . ': ' . $e->getMessage());
                }
            }
        });
    }
}
