<?php
// app/Http/Controllers/Api/UserController.php
// VFRB Enterprise — User Management
//
// SCHEMA VERIFIED against vfrb_db.sql:
//   users: user_id (PK), name, email, password, google_id, avatar,
//          contact_number, organization_name, address,
//          client_type (enum: corporate|school|government|medical|organization; legacy: individual),
//          email_verified_at, remember_token
//   model_has_roles: role_id, model_type ('App\Models\User'), model_id
//   roles: id, name (customer|staff|manager)
//
// RULES:
//   - PK is user_id (never id)
//   - No role column on users table — read/write via model_has_roles
//   - Manager creates ALL staff accounts (no self-registration for staff)
//   - No supplier portal. No supplier login. Ever.
//
// UserManagement.jsx sends for create:
//   { name, email, password, password_confirmation, role, contact_number }
// Profile.jsx sends for update:
//   { name, contact_number, address, organization_name, client_type }
// Profile.jsx sends for password change:
//   { current_password, password, password_confirmation }

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password as PasswordRule;

class UserController extends Controller
{
    // ── GET /api/admin/users ──────────────────────────────────────────────────
    // UserManagement.jsx: staff + manager user list (excludes customers)
    public function adminIndex(Request $request)
    {
        $perPage = (int) $request->input('per_page', 20);
        $search  = $request->input('search', '');

        // Only show staff and manager accounts
        $staffRoleIds = DB::table('roles')
            ->whereIn('name', ['staff', 'manager'])
            ->pluck('id');

        $userIds = DB::table('model_has_roles')
            ->whereIn('role_id', $staffRoleIds)
            ->pluck('model_id');

        $query = DB::table('users')
            ->whereIn('user_id', $userIds)
            ->select('user_id', 'name', 'email', 'contact_number', 'job_function',
                     'organization_name', 'email_verified_at', 'created_at', 'updated_at');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $users = $query->orderBy('name')->paginate($perPage);

        // Inject role into each user
        $users->getCollection()->transform(function ($user) {
            $user->role = DB::table('model_has_roles')
                ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                ->where('model_has_roles.model_id', $user->user_id)
                ->value('roles.name') ?? 'staff';
            $user->is_active = !is_null($user->email_verified_at);
            return $user;
        });

        return response()->json($users);
    }

    // ── GET /api/admin/customers ─────────────────────────────────────────────
    // Client master data (read-only). Customers are `users` rows holding the
    // 'customer' role — no separate table. Exposes ONLY profile fields and order
    // aggregates; never password / google_id / remember_token / avatar.
    // Query: search (name/email/organization/contact), client_type, per_page (max 100)
    public function adminCustomers(Request $request)
    {
        $perPage = min(max((int) $request->input('per_page', 25), 1), 100);
        $search  = trim((string) $request->input('search', ''));
        $type    = $request->input('client_type');

        $customerIds = DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('roles.name', 'customer')
            ->select('model_has_roles.model_id');

        $orderAgg = DB::table('orders')
            ->select(
                'user_id',
                DB::raw('COUNT(*) as orders_count'),
                DB::raw("SUM(CASE WHEN status NOT IN ('completed','cancelled') THEN 1 ELSE 0 END) as active_orders"),
                DB::raw('MAX(created_at) as last_order_at')
            )
            ->groupBy('user_id');

        $query = DB::table('users')
            ->leftJoinSub($orderAgg, 'oa', 'oa.user_id', '=', 'users.user_id')
            ->whereIn('users.user_id', $customerIds)
            ->select(
                'users.user_id', 'users.name', 'users.email', 'users.contact_number',
                'users.organization_name', 'users.business_registration_number', 'users.address', 'users.client_type',
                'users.email_verified_at', 'users.created_at',
                DB::raw('COALESCE(oa.orders_count, 0) as orders_count'),
                DB::raw('COALESCE(oa.active_orders, 0) as active_orders'),
                'oa.last_order_at'
            );

        if ($type && in_array($type, ['individual', 'corporate', 'school', 'medical'], true)) {
            $query->where('users.client_type', $type);
        }
        if ($search !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
            $query->where(function ($q) use ($like) {
                $q->where('users.name', 'like', $like)
                  ->orWhere('users.email', 'like', $like)
                  ->orWhere('users.organization_name', 'like', $like)
                  ->orWhere('users.contact_number', 'like', $like);
            });
        }

        $summary = DB::table('users')
            ->whereIn('user_id', $customerIds)
            ->select('client_type', DB::raw('COUNT(*) as total'))
            ->groupBy('client_type')
            ->pluck('total', 'client_type');

        $page = $query->orderBy('users.name')->paginate($perPage);

        return response()->json(array_merge($page->toArray(), [
            'summary' => [
                'total'      => (int) $summary->sum(),
                'individual' => (int) ($summary['individual'] ?? 0),
                'corporate'  => (int) ($summary['corporate'] ?? 0),
                'school'     => (int) ($summary['school'] ?? 0),
                'medical'    => (int) ($summary['medical'] ?? 0),
            ],
        ]));
    }

    // ── POST /api/admin/users  AND  POST /api/admin/users/create ─────────────
    // UserManagement.jsx CreateModal sends: { name, email, password, password_confirmation, role, contact_number }
    // Register.jsx also uses /api/admin/users/create (same method, same route)
    public function adminCreate(Request $request)
    {
        $request->validate([
            'name'                  => 'required|string|max:100',
            'email'                 => ['required', 'email:rfc,dns', 'max:100', 'unique:users,email'],
            'password'              => ['required', 'confirmed', PasswordRule::min(8)->mixedCase()->numbers()->symbols()],
            'role' => 'required|in:staff,manager', // customers self-register only
            'job_function'          => 'nullable|in:general,production,inventory,sales',
            'contact_number'        => 'nullable|string|max:20',
            'organization_name'     => 'nullable|string|max:100',
        ], [
            'email.unique' => 'An account with this email already exists.',
            'email.email'  => 'Please enter a real, valid email address.',
        ]);

        $userId = DB::table('users')->insertGetId([
            'name'              => $request->input('name'),
            'email'             => $request->input('email'),
            'password'          => Hash::make($request->input('password')),
            'contact_number'    => $request->input('contact_number'),
            'organization_name' => $request->input('organization_name'),
            // job_function is only meaningful for staff — managers/customers stay 'general'
            'job_function'      => $request->input('role') === 'staff'
                ? ($request->input('job_function') ?? 'general')
                : 'general',
            // Auto-verify staff/manager accounts (manager creates them directly)
            'email_verified_at' => in_array($request->input('role'), ['staff','manager']) ? now() : null,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        // Assign role via Spatie model_has_roles
        $roleId = DB::table('roles')
            ->where('name', $request->input('role'))
            ->value('id');

        if ($roleId) {
            DB::table('model_has_roles')->insert([
                'role_id'    => $roleId,
                'model_type' => 'App\\Models\\User',
                'model_id'   => $userId,
            ]);
        }

        $user       = DB::table('users')->where('user_id', $userId)->first();
        $user->role = $request->input('role');

        return response()->json($user, 201);
    }

    // ── PUT /api/admin/users/{id} ──────────────────────────────────────────────
    // UserManagement.jsx: edit an existing staff/manager's role/job_function
    // (previously only creatable, never editable after — DB access was the
    // only way to change it).
    public function adminUpdate(Request $request, int $id)
    {
        $user = DB::table('users')->where('user_id', $id)->first();
        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $request->validate([
            'role'         => 'required|in:staff,manager',
            'job_function' => 'nullable|in:general,production,inventory,sales',
        ]);

        $newRole = $request->input('role');
        DB::table('users')->where('user_id', $id)->update([
            'job_function' => $newRole === 'staff' ? ($request->input('job_function') ?? 'general') : 'general',
            'updated_at'   => now(),
        ]);

        $roleId = DB::table('roles')->where('name', $newRole)->value('id');
        if ($roleId) {
            DB::table('model_has_roles')->where('model_id', $id)->where('model_type', 'App\\Models\\User')->delete();
            DB::table('model_has_roles')->insert(['role_id' => $roleId, 'model_type' => 'App\\Models\\User', 'model_id' => $id]);
        }

        $updated       = DB::table('users')->where('user_id', $id)->first();
        $updated->role = $newRole;

        return response()->json($updated);
    }

    // ── PATCH /api/admin/users/{id}/toggle ────────────────────────────────────
    // UserManagement.jsx activate/deactivate a user
    // Implementation: null out email_verified_at to "deactivate", set it to now() to "activate"
    // (The frontend just toggles — it checks is_active flag)
    public function adminToggle(int $id)
    {
        $user = DB::table('users')->where('user_id', $id)->first();
        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        // Cannot deactivate yourself
        if ($id === Auth::id()) {
            return response()->json(['message' => 'You cannot deactivate your own account.'], 422);
        }

        $isStaffOrManager = DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('model_has_roles.model_type', 'App\\Models\\User')
            ->where('model_has_roles.model_id', $id)
            ->whereIn('roles.name', ['staff', 'manager'])
            ->exists();
        if (!$isStaffOrManager) {
            return response()->json(['message' => 'Only staff and manager accounts can be activated or deactivated.'], 422);
        }

        // Toggle: if email_verified_at is set → clear it (deactivate); if null → set it (activate)
        $newVerified = $user->email_verified_at ? null : now();

        DB::table('users')
            ->where('user_id', $id)
            ->update([
                'email_verified_at' => $newVerified,
                'updated_at'        => now(),
            ]);

        if (!$newVerified) {
            DB::table('personal_access_tokens')
                ->where('tokenable_type', 'App\\Models\\User')
                ->where('tokenable_id', $id)
                ->delete();
        }

        return response()->json([
            'message'   => $newVerified ? 'User activated.' : 'User deactivated.',
            'is_active' => !is_null($newVerified),
        ]);
    }

    // ── GET /api/customer/profile ─────────────────────────────────────────────
    // Profile.jsx loads the current customer's own profile
    public function customerProfile()
    {
        $userId = Auth::id();
        $user   = DB::table('users')
            ->where('user_id', $userId)
            ->select('user_id', 'name', 'email', 'contact_number',
                     'organization_name', 'address', 'client_type',
                     'avatar', 'email_verified_at', 'created_at')
            ->first();

        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $user->role = DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('model_has_roles.model_id', $userId)
            ->value('roles.name') ?? 'client';

        return response()->json(['user' => $user]);
    }

    // ── PUT /api/customer/profile ─────────────────────────────────────────────
    // Profile.jsx saveProfile(): { name, contact_number, address, organization_name, client_type }
    public function customerUpdateProfile(Request $request)
    {
        $request->validate([
            'name'              => 'required|string|max:100',
            'contact_number'    => 'nullable|string|max:20',
            'address'           => 'nullable|string|max:255',
            'organization_name' => 'nullable|string|max:100',
            'client_type'       => 'nullable|in:individual,corporate,school,medical,government,organization',
        ]);

        DB::table('users')
            ->where('user_id', Auth::id())
            ->update([
                'name'              => $request->input('name'),
                'contact_number'    => $request->input('contact_number'),
                'address'           => $request->input('address'),
                'organization_name' => $request->input('organization_name'),
                'client_type'       => $request->input('client_type'),
                'updated_at'        => now(),
            ]);

        return response()->json(['message' => 'Profile updated successfully.']);
    }

    // ── PUT /api/customer/profile/password ────────────────────────────────────
    // Profile.jsx savePw(): { current_password, password, password_confirmation }
    public function customerUpdatePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required|string',
            'password'         => ['required', 'confirmed', PasswordRule::min(8)->mixedCase()->numbers()->symbols()],
        ]);

        $user = DB::table('users')->where('user_id', Auth::id())->first();

        if (!Hash::check($request->input('current_password'), $user->password)) {
            return response()->json(['message' => 'Current password is incorrect.'], 422);
        }

        DB::table('users')
            ->where('user_id', Auth::id())
            ->update([
                'password'   => Hash::make($request->input('password')),
                'updated_at' => now(),
            ]);

        return response()->json(['message' => 'Password changed successfully.']);
    }
}