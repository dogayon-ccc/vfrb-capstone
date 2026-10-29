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
//   roles: id, name (customer|staff|manager|owner) — owner is read-only, edited by managers only
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
use App\Http\Middleware\RoleMiddleware;
use App\Models\AuditLog;
use App\Rules\PhMobile;
use App\Services\ProductionStageService;
use App\Support\Sql;
use App\Support\UploadDisk;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;

class UserController extends Controller
{
    private const CLIENT_TYPES = ['individual', 'corporate', 'school', 'medical', 'government', 'organization'];

    // ── GET /api/admin/users ──────────────────────────────────────────────────
    // UserManagement.jsx: staff, manager and owner user list (excludes customers)
    public function adminIndex(Request $request)
    {
        $perPage = (int) $request->input('per_page', 20);
        $search = $request->input('search', '');

        // Staff, manager and owner accounts with their role in one join (was a role query per user).
        $query = DB::table('users')
            ->join('model_has_roles as mr', 'mr.model_id', '=', 'users.user_id')
            ->join('roles as r', 'r.id', '=', 'mr.role_id')
            ->whereIn('r.name', ['staff', 'manager', 'owner'])
            ->select('users.user_id', 'users.name', 'users.email', 'users.contact_number', 'users.job_function', 'users.assigned_stage',
                'users.organization_name', 'users.email_verified_at', 'users.created_at', 'users.updated_at', 'r.name as role');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('users.name', Sql::like(), "%{$search}%")
                    ->orWhere('users.email', Sql::like(), "%{$search}%");
            });
        }

        $users = $query->orderBy('users.name')->paginate($perPage);

        $users->getCollection()->transform(function ($user) {
            $user->is_active = ! is_null($user->email_verified_at);

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
        $search = trim((string) $request->input('search', ''));
        $type = $request->input('client_type');

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

        if ($type && in_array($type, self::CLIENT_TYPES, true)) {
            $query->where('users.client_type', $type);
        }
        if ($search !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';
            $query->where(function ($q) use ($like) {
                $q->where('users.name', Sql::like(), $like)
                    ->orWhere('users.email', Sql::like(), $like)
                    ->orWhere('users.organization_name', Sql::like(), $like)
                    ->orWhere('users.contact_number', Sql::like(), $like);
            });
        }

        $summary = DB::table('users')
            ->whereIn('user_id', $customerIds)
            ->select('client_type', DB::raw('COUNT(*) as total'))
            ->groupBy('client_type')
            ->pluck('total', 'client_type');

        $page = $query->orderBy('users.name')->paginate($perPage);

        $byType = array_map(fn ($t) => (int) ($summary[$t] ?? 0), array_combine(self::CLIENT_TYPES, self::CLIENT_TYPES));
        $total = (int) $summary->sum();

        return response()->json(array_merge($page->toArray(), [
            'summary' => ['total' => $total] + $byType + ['untyped' => $total - array_sum($byType)],
        ]));
    }

    // ── POST /api/admin/users  AND  POST /api/admin/users/create ─────────────
    // UserManagement.jsx CreateModal sends: { name, email, password, password_confirmation, role, contact_number,
    // and for customers: username, organization_name, client_type }. Customers cannot self-register; they are created here.
    public function adminCreate(Request $request)
    {
        $isCustomer = $request->input('role') === 'customer';
        if ($isCustomer) {
            $request->merge(['username' => strtolower(trim((string) $request->input('username'))) ?: null]);
        }
        $request->validate([
            'name' => 'required|string|max:100',
            'email' => ['required', 'email:rfc,dns', 'max:100', 'unique:users,email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)->mixedCase()->numbers()->symbols()],
            'role' => 'required|in:customer,staff,manager,owner|exists:roles,name',
            'assigned_stage' => ($request->input('role') === 'staff' ? 'required' : 'prohibited').'|nullable|in:'.implode(',', ProductionStageService::productionStages()),
            'contact_number' => 'nullable|string|max:20',
            'organization_name' => ($isCustomer ? 'required' : 'nullable').'|string|max:100',
            'username' => ['nullable', 'string', 'min:3', 'max:30', 'regex:/^[a-z0-9._]+$/', 'unique:users,username'],
            'client_type' => ($isCustomer ? 'required' : 'nullable').'|in:corporate,school,government,medical,organization',
        ], [
            'email.unique' => 'An account with this email already exists.',
            'email.email' => 'Please enter a real, valid email address.',
            'username.unique' => 'That username is taken.',
            'username.regex' => 'Use 3-30 letters, numbers, dots or underscores.',
        ]);

        if (! $this->canManageRole($request, $request->input('role'))) {
            return response()->json(['message' => 'Only an Administrator (Manager) can create Administrator or Owner accounts.'], 403);
        }

        $userId = DB::table('users')->insertGetId([
            'name' => $request->input('name'),
            'email' => $request->input('email'),
            'password' => Hash::make($request->input('password')),
            'contact_number' => $request->input('contact_number'),
            'organization_name' => $request->input('organization_name'),
            'username' => $isCustomer ? $request->input('username') : null,
            'client_type' => $isCustomer ? $request->input('client_type') : null,
            'job_function' => 'general',
            'assigned_stage' => $request->input('role') === 'staff' ? $request->input('assigned_stage') : null,
            // Accounts created by an Administrator or the Owner are granted access immediately.
            'email_verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], 'user_id');

        // Assign role via Spatie model_has_roles
        $roleId = DB::table('roles')
            ->where('name', $request->input('role'))
            ->value('id');

        if ($roleId) {
            DB::table('model_has_roles')->insert([
                'role_id' => $roleId,
                'model_type' => 'App\\Models\\User',
                'model_id' => $userId,
            ]);
        }

        RoleMiddleware::forgetRole($userId);
        AuditLog::record('user_created', 'users', $userId);

        $user = DB::table('users')->where('user_id', $userId)->first();
        $user->role = $request->input('role');
        unset($user->password, $user->remember_token);

        // New customers get an emailed link to set their own password; the typed one still works until then.
        if ($isCustomer) {
            try {
                $user->password_link_sent = Password::sendResetLink(['email' => $user->email]) === Password::RESET_LINK_SENT;
            } catch (\Throwable $e) {
                report($e);
                $user->password_link_sent = false;
            }
        }

        return response()->json($user, 201);
    }

    // ── PUT /api/admin/users/{id} ──────────────────────────────────────────────
    // UserManagement.jsx: edit an existing staff/manager's role/job_function
    // (previously only creatable, never editable after — DB access was the
    // only way to change it).
    public function adminUpdate(Request $request, int $id)
    {
        $user = DB::table('users')->where('user_id', $id)->first();
        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }
        // A customer account is never promoted into staff, manager or owner from here.
        if (! $this->isAdminAccount($id)) {
            return response()->json(['message' => 'Only Administrator and Owner accounts can be edited here.'], 422);
        }

        $oldRole = $this->roleName($id);
        if (! $this->canManageRole($request, $oldRole)) {
            return response()->json(['message' => 'Only an Administrator (Manager) can change Administrator or Owner accounts.'], 403);
        }
        $request->validate([
            'role' => 'required|in:staff,manager,owner|exists:roles,name',
            'assigned_stage' => ($request->input('role') === 'staff' ? 'required' : 'prohibited').'|nullable|in:'.implode(',', ProductionStageService::productionStages()),
        ]);

        $newRole = $request->input('role');
        if (! $this->canManageRole($request, $newRole)) {
            return response()->json(['message' => 'Only an Administrator (Manager) can change Administrator or Owner accounts.'], 403);
        }
        DB::table('users')->where('user_id', $id)->update([
            'job_function' => 'general',
            'assigned_stage' => $newRole === 'staff' ? $request->input('assigned_stage') : null,
            'updated_at' => now(),
        ]);

        $roleId = DB::table('roles')->where('name', $newRole)->value('id');
        if ($roleId) {
            DB::table('model_has_roles')->where('model_id', $id)->where('model_type', 'App\\Models\\User')->delete();
            DB::table('model_has_roles')->insert(['role_id' => $roleId, 'model_type' => 'App\\Models\\User', 'model_id' => $id]);
            RoleMiddleware::forgetRole($id);
            if ($oldRole !== $newRole) {
                AuditLog::record('user_role_changed', 'users', $id);
            }
        }

        $updated = DB::table('users')->where('user_id', $id)->first();
        $updated->role = $newRole;

        return response()->json($updated);
    }

    // ── PATCH /api/admin/users/{id}/toggle ────────────────────────────────────
    // UserManagement.jsx activate/deactivate a user
    // Implementation: null out email_verified_at to "deactivate", set it to now() to "activate"
    // (The frontend just toggles — it checks is_active flag)
    public function adminToggle(Request $request, int $id)
    {
        $user = DB::table('users')->where('user_id', $id)->first();
        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        // Cannot deactivate yourself
        if ($id === Auth::id()) {
            return response()->json(['message' => 'You cannot deactivate your own account.'], 422);
        }

        if (! $this->isAdminAccount($id)) {
            return response()->json(['message' => 'Only Administrator and Owner accounts can be activated or deactivated.'], 422);
        }
        if (! $this->canManageRole($request, $this->roleName($id))) {
            return response()->json(['message' => 'Only an Administrator (Manager) can activate or deactivate Administrator or Owner accounts.'], 403);
        }

        // Toggle: if email_verified_at is set → clear it (deactivate); if null → set it (activate)
        $newVerified = $user->email_verified_at ? null : now();

        DB::table('users')
            ->where('user_id', $id)
            ->update([
                'email_verified_at' => $newVerified,
                'updated_at' => now(),
            ]);
        AuditLog::record($newVerified ? 'user_activated' : 'user_deactivated', 'users', $id);

        if (! $newVerified) {
            DB::table('personal_access_tokens')
                ->where('tokenable_type', 'App\\Models\\User')
                ->where('tokenable_id', $id)
                ->delete();
        }

        return response()->json([
            'message' => $newVerified ? 'User activated.' : 'User deactivated.',
            'is_active' => ! is_null($newVerified),
        ]);
    }

    // ── GET /api/customer/profile ─────────────────────────────────────────────
    // Profile.jsx loads the current customer's own profile
    public function customerProfile()
    {
        $userId = Auth::id();
        $user = DB::table('users')
            ->where('user_id', $userId)
            ->select('user_id', 'name', 'email', 'contact_number',
                'organization_name', 'address', 'client_type',
                'avatar', 'email_verified_at', 'created_at')
            ->first();

        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $user->role = DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('model_has_roles.model_id', $userId)
            ->value('roles.name') ?? 'client';
        $user->avatar = UploadDisk::url($user->avatar); // stored path → URL, same as /api/user

        return response()->json(['user' => $user]);
    }

    // ── PUT /api/customer/profile ─────────────────────────────────────────────
    // Profile.jsx saveProfile(): { name, contact_number, address, organization_name, client_type }
    public function customerUpdateProfile(Request $request)
    {
        $request->merge(['contact_number' => PhMobile::normalize($request->input('contact_number'))]);
        $request->validate([
            'name' => 'required|string|max:100',
            'contact_number' => ['nullable', 'string', 'max:20', new PhMobile],
            'address' => 'nullable|string|max:255',
            'organization_name' => 'nullable|string|max:100',
            'client_type' => 'nullable|in:individual,corporate,school,medical,government,organization',
        ]);

        DB::table('users')
            ->where('user_id', Auth::id())
            ->update([
                'name' => $request->input('name'),
                'contact_number' => $request->input('contact_number'),
                'address' => $request->input('address'),
                'organization_name' => $request->input('organization_name'),
                'client_type' => $request->input('client_type'),
                'updated_at' => now(),
            ]);

        return response()->json(['message' => 'Profile updated successfully.']);
    }

    // ── PUT /api/customer/profile/password ────────────────────────────────────
    // Profile.jsx savePw(): { current_password, password, password_confirmation }
    public function customerUpdatePassword(Request $request)
    {
        return $this->changeOwnPassword($request);
    }

    // ── PUT /api/admin/profile/password ───────────────────────────────────────
    // Staff and managers change their own password with the same rules as customers.
    public function adminUpdatePassword(Request $request)
    {
        return $this->changeOwnPassword($request);
    }

    // The signed-in user's own password: current password must match; new one >= 8 with mixed case, a number and a symbol.
    private function changeOwnPassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required|string',
            'password' => ['required', 'confirmed', PasswordRule::min(8)->mixedCase()->numbers()->symbols()],
        ]);

        $user = DB::table('users')->where('user_id', Auth::id())->first();

        if (! Hash::check($request->input('current_password'), $user->password)) {
            return response()->json(['message' => 'Current password is incorrect.'], 422);
        }

        DB::table('users')
            ->where('user_id', Auth::id())
            ->update([
                'password' => Hash::make($request->input('password')),
                'updated_at' => now(),
            ]);

        return response()->json(['message' => 'Password changed successfully.']);
    }

    private function roleName(int $id): ?string
    {
        return DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('model_has_roles.model_type', 'App\\Models\\User')
            ->where('model_has_roles.model_id', $id)
            ->value('roles.name');
    }

    // The Owner is read-only (OwnerReadOnly), so the Administrator (Manager) manages every account.
    private function canManageRole(Request $request, ?string $role): bool
    {
        return RoleMiddleware::roleOf($request) === 'manager';
    }

    private function isAdminAccount(int $id): bool
    {
        return DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('model_has_roles.model_type', 'App\\Models\\User')
            ->where('model_has_roles.model_id', $id)
            ->whereIn('roles.name', ['staff', 'manager', 'owner'])
            ->exists();
    }
}
