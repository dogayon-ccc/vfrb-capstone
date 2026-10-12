<?php

namespace App\Notifications\Concerns;

use App\Models\NotificationPreference;

// Mail goes out only while the user's "Email notifications" setting is on (no row yet = on). Not used for verify/reset mails.
trait RespectsEmailPreference
{
    protected function mailChannels(object $notifiable): array
    {
        $pref = NotificationPreference::find($notifiable->user_id ?? null);
        return $pref && !$pref->email_enabled ? [] : ['mail'];
    }

    // Customer and admin portal links; customer routes have no /customer prefix (src/App.jsx).
    protected function portalUrl(string $path): string
    {
        return rtrim(config('app.frontend_url', 'http://localhost:5173'), '/') . $path;
    }
}
