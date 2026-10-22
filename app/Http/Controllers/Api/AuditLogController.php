<?php
// app/Http/Controllers/Api/AuditLogController.php
// Audit Log (Oct 16 2026): reads the insert-only audit_logs table — login, logout,
// failed login and key writes (see AuditLog::ACTIONS). Read-only: no store/update/delete
// endpoint exists. Manager and owner only (route group).
// The operational "who did what" view stays in ActivityLogController.

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AuditLogController extends Controller
{
    // ── GET /api/admin/audit-logs ────────────────────────────────────────────
    // Query params (all optional): user_id, action, entity, entity_id,
    // date_from, date_to (YYYY-MM-DD, inclusive), per_page (default 20).
    public function index(Request $request)
    {
        $request->validate([
            'user_id'   => 'sometimes|integer',
            'action'    => ['sometimes', 'string', Rule::in(AuditLog::ACTIONS)],
            'entity'    => 'sometimes|string|max:50',
            'entity_id' => 'sometimes|integer',
            'date_from' => 'sometimes|date',
            'date_to'   => 'sometimes|date',
            'per_page'  => 'sometimes|integer|min:1|max:100',
        ]);

        $query = DB::table('audit_logs as a')
            ->leftJoin('users as u', 'u.user_id', '=', 'a.user_id')
            ->select('a.audit_id', 'a.user_id', 'u.name as user_name', 'a.action', 'a.entity', 'a.entity_id', 'a.ip', 'a.created_at');

        foreach (['user_id', 'action', 'entity', 'entity_id'] as $field) {
            if ($request->filled($field)) {
                $query->where("a.{$field}", $request->input($field));
            }
        }
        if ($request->filled('date_from')) {
            $query->whereDate('a.created_at', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('a.created_at', '<=', $request->input('date_to'));
        }

        $page = $query->orderByDesc('a.created_at')->orderByDesc('a.audit_id')
            ->paginate((int) $request->input('per_page', 20));

        return response()->json([
            'data'         => $page->items(),
            'actions'      => AuditLog::ACTIONS,
            'current_page' => $page->currentPage(),
            'last_page'    => $page->lastPage(),
            'total'        => $page->total(),
            'per_page'     => $page->perPage(),
        ]);
    }
}
