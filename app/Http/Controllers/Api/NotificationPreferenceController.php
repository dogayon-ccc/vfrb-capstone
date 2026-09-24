<?php
// app/Http/Controllers/Api/NotificationPreferenceController.php
// Model + table already existed (notification_preferences, one row per
// user); only this controller and its routes were missing — the backend
// wasn't "half-built," it was fully built except for this file.
// show() auto-creates a default row on first access rather than 404ing,
// since every user implicitly has preferences (the table's own column
// defaults — email_enabled=1, sms_enabled=0 — ARE the intended default).

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NotificationPreference;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class NotificationPreferenceController extends Controller
{
    public function show()
    {
        return NotificationPreference::firstOrCreate(['user_id' => Auth::id()]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'email_enabled' => 'required|boolean',
            'sms_enabled'   => 'required|boolean',
        ]);

        $pref = NotificationPreference::firstOrCreate(['user_id' => Auth::id()]);
        $pref->update($data);

        return $pref;
    }
}
