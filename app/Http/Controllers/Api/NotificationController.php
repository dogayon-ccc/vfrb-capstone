<?php
// app/Http/Controllers/Api/NotificationController.php
// VFRB Enterprise — Notifications
//
// SCHEMA VERIFIED against vfrb_db.sql:
//   notifications: notif_id (PK), user_id, order_id (nullable),
//                  message (text), type (varchar(80) default 'general'),
//                  title (varchar(200) nullable), is_read (tinyint default 0),
//                  date_sent (timestamp), created_at, updated_at
//
// RULES:
//   - PK is notif_id (NOT id — never use 'id')
//   - No role column on users table — use model_has_roles + roles
//   - Customer and admin use different endpoints (different guards)
//
// ROUTES (in api.php):
//   Customer (auth:sanctum + role:customer):
//     GET    /api/customer/notifications
//     PATCH  /api/customer/notifications/{id}/read
//     POST   /api/customer/notifications/read-all
//
//   Admin / Staff / Manager (auth:sanctum + role:staff,manager):
//     GET    /api/admin/notifications
//     PATCH  /api/admin/notifications/{id}/read
//     POST   /api/admin/notifications/read-all

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class NotificationController extends Controller
{
    // ── Customer: GET /api/customer/notifications ─────────────────────────────
    // ── Customer: GET /api/customer/notifications/summary ───────────────────────
    // CustomerLayout.jsx bell badge — unread count only
    public function customerSummary()
    {
        $count = DB::table('notifications')
            ->where('user_id', Auth::id())
            ->where('is_read', 0)
            ->count();
        return response()->json(['unread_count' => $count]);
    }

        public function customerIndex(Request $request)
    {
        $perPage = (int) $request->input('per_page', 20);
        $userId  = Auth::id();

        $rows = DB::table('notifications')
            ->where('user_id', $userId)
            ->orderByDesc('date_sent')
            ->paginate($perPage);

        return response()->json($rows);
    }

    // ── Customer: PATCH /api/customer/notifications/{id}/read ─────────────────
    public function customerMarkRead(int $id)
    {
        $affected = DB::table('notifications')
            ->where('notif_id', $id)
            ->where('user_id', Auth::id())
            ->update([
                'is_read'    => 1,
                'updated_at' => now(),
            ]);

        if (!$affected) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return response()->json(['message' => 'Marked as read.']);
    }

    // ── Customer: POST /api/customer/notifications/read-all ───────────────────
    public function customerMarkAllRead()
    {
        DB::table('notifications')
            ->where('user_id', Auth::id())
            ->where('is_read', 0)
            ->update([
                'is_read'    => 1,
                'updated_at' => now(),
            ]);

        return response()->json(['message' => 'All notifications marked as read.']);
    }

    // ── Admin: GET /api/admin/notifications ───────────────────────────────────
    public function adminIndex(Request $request)
    {
        $perPage = (int) $request->input('per_page', 20);
        $userId  = Auth::id();

        $rows = DB::table('notifications')
            ->where('user_id', $userId)
            ->orderByDesc('date_sent')
            ->paginate($perPage);

        return response()->json($rows);
    }

    // ── Admin: PATCH /api/admin/notifications/{id}/read ───────────────────────
    public function adminMarkRead(int $id)
    {
        $affected = DB::table('notifications')
            ->where('notif_id', $id)
            ->where('user_id', Auth::id())
            ->update([
                'is_read'    => 1,
                'updated_at' => now(),
            ]);

        if (!$affected) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return response()->json(['message' => 'Marked as read.']);
    }

    // ── Admin: POST /api/admin/notifications/read-all ─────────────────────────
    public function adminMarkAllRead()
    {
        DB::table('notifications')
            ->where('user_id', Auth::id())
            ->where('is_read', 0)
            ->update([
                'is_read'    => 1,
                'updated_at' => now(),
            ]);

        Cache::forget('dashboard_stats');

        return response()->json(['message' => 'All notifications marked as read.']);
    }

    // ── Admin: unread count (used by Dashboard bell badge) ───────────────────
    // GET /api/admin/notifications/unread-count
    public function adminUnreadCount()
    {
        $count = DB::table('notifications')
            ->where('user_id', Auth::id())
            ->where('is_read', 0)
            ->count();

        return response()->json(['unread_count' => $count]);
    }
}
