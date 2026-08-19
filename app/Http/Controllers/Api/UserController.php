<?php
// app/Http/Controllers/Api/UserController.php
// VFRB Enterprise — User Management
//
// SCHEMA VERIFIED against vfrb_db.sql:
//   users: user_id (PK), name, email, password, google_id, avatar,
//          contact_number, organization_name, address,
//          client_type (enum: individual|corporate|school|medical),
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
            ->select('user_id', 'name', 'email', 'contact_number',
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
            $user->is_active = !is_null($user->email_verified_at) || true; // all created users are active
            return $user;
        });

        return response()->json($users);
    }

    // ── POST /api/admin/users  AND  POST /api/admin/users/create ─────────────
    // UserManagement.jsx CreateModal sends: { name, email, password, password_confirmation, role, contact_number }
    // Register.jsx also uses /api/admin/users/create (same method, same route)
    public function adminCreate(Request $request)
    {
        $request->validate([
            'name'                  => 'required|string|max:100',
            'email'                 => 'required|email|max:100|unique:users,email',
            'password'              => 'required|string|min:8|confirmed',
            'role' => 'required|in:staff,manager', // customers self-register only
            'contact_number'        => 'nullable|string|max:20',
            'organization_name'     => 'nullable|string|max:100',
        ], [
            'email.unique' => 'An account with this email already exists.',
        ]);

        $userId = DB::table('users')->insertGetId([
            'name'              => $request->input('name'),
            'email'             => $request->input('email'),
            'password'          => Hash::make($request->input('password')),
            'contact_number'    => $request->input('contact_number'),
            'organization_name' => $request->input('organization_name'),
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

        // Toggle: if email_verified_at is set → clear it (deactivate); if null → set it (activate)
        $newVerified = $user->email_verified_at ? null : now();

        DB::table('users')
            ->where('user_id', $id)
            ->update([
                'email_verified_at' => $newVerified,
                'updated_at'        => now(),
            ]);

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
            ->value('roles.name') ?? 'customer';

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
            'client_type'       => 'nullable|in:individual,corporate,school,medical',
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
            'password'         => 'required|string|min:8|confirmed',
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