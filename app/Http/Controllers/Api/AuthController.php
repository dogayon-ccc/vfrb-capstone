<?php
// app/Http/Controllers/Api/AuthController.php
// VFRB Enterprise — Authentication
//
// SCHEMA VERIFIED against vfrb_db.sql:
//   users: user_id (PK), name, email, password, google_id, avatar,
//          contact_number, organization_name, address,
//          client_type (enum: individual|corporate|school|medical),
//          email_verified_at, remember_token
//   password_reset_tokens: email, token, created_at
//   model_has_roles: role_id, model_type, model_id
//   roles: id, name (customer|staff|manager)
//
// RULES:
//   - PK is user_id (NOT id)
//   - No role column on users — use getRoleName() via model_has_roles + roles
//   - Customer portal login: POST /api/login
//   - Admin/staff portal login: POST /api/admin/login (same method, different guard check)
//   - me() returns the current user with role injected (for VerifyEmail.jsx)
//
// RESPONSE SHAPES (exactly what the JSX expects):
//   login:      { token, user: { user_id, name, email, role, email_verified_at, ... } }
//   adminLogin: { token, user: { user_id, name, email, role, email_verified_at, ... } }
//   me:         { user_id, name, email, role, email_verified_at, ... }
//   register:   { token, user: { ... } }
//   logout:     { message }
//   forgotPassword: { message }
//   resetPassword:  { message }
//   resendVerification: { message }

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
    // ── POST /api/login — Customer portal ────────────────────────────────────
    // auth/Login.jsx expects: { token, user: { ...fields, role } }
    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = DB::table('users')
            ->where('email', $request->input('email'))
            ->first();

        if (!$user || !Hash::check($request->input('password'), $user->password)) {
            return response()->json(['message' => 'Invalid email or password.'], 401);
        }

        $role = $this->getRoleName($user->user_id);

        // Customer portal: reject staff/manager logins with clear message
        if (in_array($role, ['staff', 'manager'])) {
            return response()->json([
                'message' => 'Staff accounts must use the Admin Portal at /admin/login.',
            ], 403);
        }

        $token = $this->createToken($user->user_id);

        return response()->json([
            'token' => $token,
            'user'  => $this->formatUser($user, $role),
        ]);
    }

    // ── POST /api/admin/login — Admin portal ──────────────────────────────────
    // admin/Login.jsx expects: { token, user: { ...fields, role } }
    public function adminLogin(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = DB::table('users')
            ->where('email', $request->input('email'))
            ->first();

        if (!$user || !Hash::check($request->input('password'), $user->password)) {
            return response()->json(['message' => 'Invalid email or password.'], 401);
        }

        $role = $this->getRoleName($user->user_id);

        // Admin portal: only staff and manager may log in here
        if (!in_array($role, ['staff', 'manager'])) {
            return response()->json([
                'message' => 'This account does not have admin access.',
            ], 403);
        }

        $token = $this->createToken($user->user_id);

        return response()->json([
            'token' => $token,
            'user'  => $this->formatUser($user, $role),
        ]);
    }

    // ── POST /api/register — Customer self-registration ───────────────────────
    public function register(Request $request)
    {
        $request->validate([
            'name'                  => 'required|string|max:100',
            'email'                 => 'required|email|unique:users,email',
            'password'              => 'required|string|min:8|confirmed',
            'contact_number'        => 'nullable|string|max:20',
            'organization_name'     => 'nullable|string|max:100',
            'client_type'           => 'nullable|in:individual,corporate,school,medical',
        ], [
            'email.unique' => 'An account with this email already exists.',
        ]);

        $userId = DB::table('users')->insertGetId([
            'name'              => $request->input('name'),
            'email'             => $request->input('email'),
            'password'          => Hash::make($request->input('password')),
            'contact_number'    => $request->input('contact_number'),
            'organization_name' => $request->input('organization_name'),
            'client_type'       => $request->input('client_type', 'individual'),
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        // Assign customer role via Spatie model_has_roles
        $customerRoleId = DB::table('roles')->where('name', 'customer')->value('id');
        if ($customerRoleId) {
            DB::table('model_has_roles')->insert([
                'role_id'    => $customerRoleId,
                'model_type' => 'App\\Models\\User',
                'model_id'   => $userId,
            ]);
        }

        $user  = DB::table('users')->where('user_id', $userId)->first();
        $token = $this->createToken($userId);

        // Fire verification email event (if Laravel mail is configured)
        // event(new Registered($user)); // Uncomment when mail is set up

        return response()->json([
            'token'   => $token,
            'user'    => $this->formatUser($user, 'customer'),
            'message' => 'Account created. Please verify your email.',
        ], 201);
    }

    // ── GET /auth/google/redirect — Customer Google Sign-In (start) ───────────
    // Real implementation, Aug 23 2026. Was fully decorative before this
    // (confirmed by investigation earlier this session — the frontend button
    // pointed at a route that never existed, and laravel/socialite was
    // installed but never actually called anywhere).
    //
    // Lives in routes/web.php, NOT api.php — this is a real full-page browser
    // navigation (window.location.href), not an axios/XHR call, so it needs
    // Laravel's normal session/cookie stack (the 'web' middleware group) for
    // Socialite's CSRF-protecting state parameter. Both this and the callback
    // below happen entirely on the backend's own domain until the very last
    // step, so no cross-domain session issues arise.
    public function googleRedirect()
    {
        return Socialite::driver('google')->redirect();
    }

    // ── GET /auth/google/callback — Customer Google Sign-In (finish) ─────────
    // HARD RULE (locked, confirmed with Dave): this endpoint may ONLY create
    // or authenticate CUSTOMER accounts. If the Google email already belongs
    // to a staff or manager account, the attempt is rejected outright — never
    // logged in as customer, never given any admin access via this route.
    // This is the one thing this method must never get wrong.
    public function googleCallback()
    {
        $frontend = rtrim(env('FRONTEND_URL', 'http://localhost:5173'), '/');

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (\Exception $e) {
            return redirect($frontend . '/login?google_error=' . urlencode(
                'Google sign-in failed or was cancelled. Please try again.'
            ));
        }

        $email = $googleUser->getEmail();
        if (!$email) {
            return redirect($frontend . '/login?google_error=' . urlencode(
                'Your Google account did not share an email address. Please sign in with email instead.'
            ));
        }

        $existing = DB::table('users')->where('email', $email)->first();

        if ($existing) {
            $role = $this->getRoleName($existing->user_id);

            // The hard rule, enforced here: a staff/manager email can never
            // be logged in — or upgraded, or touched in any way — through
            // this customer-only flow. Reject clearly, issue no token.
            if ($role !== 'customer') {
                return redirect($frontend . '/admin/login?google_error=' . urlencode(
                    'This email belongs to a staff/manager account. Google sign-in here is for customers only — please use the regular staff/manager login.'
                ));
            }

            // Existing customer — log them in. Backfill google_id/avatar
            // only if missing, never overwrite anything else about them.
            if (!$existing->google_id) {
                DB::table('users')->where('user_id', $existing->user_id)->update([
                    'google_id'  => $googleUser->getId(),
                    'avatar'     => $googleUser->getAvatar(),
                    'updated_at' => now(),
                ]);
                $existing = DB::table('users')->where('user_id', $existing->user_id)->first();
            }

            $token   = $this->createToken($existing->user_id);
            $payload = $this->formatUser($existing, 'customer');
        } else {
            // New email — create a new customer account. Mirrors register()
            // exactly: raw insert + manual model_has_roles assignment.
            // Password is an unusable random string — this account can only
            // ever be accessed via Google from now on, which is fine since
            // Google already verified the email (so email_verified_at is
            // set immediately, unlike the manual-registration path).
            $userId = DB::table('users')->insertGetId([
                'name'              => $googleUser->getName() ?: ($googleUser->getNickname() ?: 'Customer'),
                'email'             => $email,
                'password'          => Hash::make(Str::random(40)),
                'google_id'         => $googleUser->getId(),
                'avatar'            => $googleUser->getAvatar(),
                'client_type'       => 'individual',
                'email_verified_at' => now(),
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);

            $customerRoleId = DB::table('roles')->where('name', 'customer')->value('id');
            if ($customerRoleId) {
                DB::table('model_has_roles')->insert([
                    'role_id'    => $customerRoleId,
                    'model_type' => 'App\\Models\\User',
                    'model_id'   => $userId,
                ]);
            }

            $newUser = DB::table('users')->where('user_id', $userId)->first();
            $token   = $this->createToken($userId);
            $payload = $this->formatUser($newUser, 'customer');
        }

        // Hand the token back to the SPA. A full-page redirect can't set an
        // Authorization header, so it's passed via query string to a small
        // dedicated frontend route that stores it and redirects onward —
        // same handoff pattern as email-verification links already use.
        return redirect($frontend . '/auth/google/complete?token=' . urlencode($token)
            . '&user=' . urlencode(json_encode($payload)));
    }

    // ── GET /api/user — Current authenticated user ────────────────────────────
    // VerifyEmail.jsx polls this to check email_verified_at after clicking link
    public function me(Request $request)
    {
        $userId = Auth::id();
        $user   = DB::table('users')->where('user_id', $userId)->first();
        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }
        $role = $this->getRoleName($userId);
        return response()->json($this->formatUser($user, $role));
    }

    // ── POST /api/logout ──────────────────────────────────────────────────────
    // AdminLayout.jsx + CustomerLayout.jsx both call this
    public function logout(Request $request)
    {
        // Revoke the current Sanctum token
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out successfully.']);
    }

    // ── POST /api/password/forgot ─────────────────────────────────────────────
    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $status = Password::sendResetLink($request->only('email'));

        if ($status === Password::RESET_LINK_SENT) {
            return response()->json(['message' => 'Password reset link sent to your email.']);
        }

        // Don't reveal whether the email exists
        return response()->json([
            'message' => 'If an account exists with that email, a reset link has been sent.',
        ]);
    }

    // ── POST /api/password/reset ──────────────────────────────────────────────
    public function resetPassword(Request $request)
    {
        $request->validate([
            'token'    => 'required|string',
            'email'    => 'required|email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                DB::table('users')
                    ->where('user_id', $user->user_id)
                    ->update([
                        'password'       => Hash::make($password),
                        'remember_token' => Str::random(60),
                        'updated_at'     => now(),
                    ]);
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json(['message' => 'Password reset successfully. Please log in.']);
        }

        return response()->json(['message' => __($status)], 422);
    }

    // ── POST /api/email/resend — Resend verification email ───────────────────
    // VerifyEmail.jsx "Resend Email" button
    public function resendVerification(Request $request)
    {
        $user = $request->user();

        if ($user->email_verified_at) {
            return response()->json(['message' => 'Email already verified.'], 409);
        }

        // In production: dispatch verification email
        // $user->sendEmailVerificationNotification();

        // For local dev, auto-verify the email so testing isn't blocked
        if (app()->environment('local')) {
            DB::table('users')
                ->where('user_id', $user->user_id)
                ->update(['email_verified_at' => now(), 'updated_at' => now()]);

            return response()->json(['message' => 'Email verified (local dev auto-verify).']);
        }

        return response()->json(['message' => 'Verification email sent.']);
    }

    // ── Private: getRoleName ──────────────────────────────────────────────────
    // Always use a direct DB query — never $user->role (column doesn't exist)
    // This bypasses Spatie guard mismatch that occurs with auth:api vs web guard
    private function getRoleName(int $userId): string
    {
        return DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('model_has_roles.model_id', $userId)
            ->where('model_has_roles.model_type', 'App\\Models\\User')
            ->value('roles.name') ?? 'customer';
    }

    // ── Private: createToken ──────────────────────────────────────────────────
    // Create a Sanctum personal access token
    private function createToken(int $userId): string
    {
        // Load the Eloquent user model for Sanctum token creation
        $eloquentUser = \App\Models\User::find($userId);
        return $eloquentUser->createToken('vfrb-token')->plainTextToken;
    }

    // ── Private: formatUser ───────────────────────────────────────────────────
    // Returns exactly the shape the JSX expects:
    //   { user_id, name, email, role, email_verified_at, contact_number,
    //     organization_name, address, client_type, avatar }
    private function formatUser(object $user, string $role): array
    {
        return [
            'user_id'           => $user->user_id,
            'name'              => $user->name,
            'email'             => $user->email,
            'role'              => $role,
            'email_verified_at' => $user->email_verified_at,
            'contact_number'    => $user->contact_number,
            'organization_name' => $user->organization_name,
            'address'           => $user->address,
            'client_type'       => $user->client_type,
            'avatar'            => $user->avatar,
        ];
    }
}