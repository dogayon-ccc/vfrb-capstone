<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

// Seed assumed: user 5 = customer. Every test runs inside a rolled-back transaction.
//   php artisan test --filter AuthHardeningTest
class AuthHardeningTest extends TestCase
{
    use DatabaseTransactions;

    public function test_token_is_rejected_after_sanctum_expiration(): void
    {
        $token = User::findOrFail(5)->createToken('vfrb-token')->plainTextToken;

        $this->withToken($token)->getJson('/api/user')->assertOk();

        $this->travel(config('sanctum.expiration') + 1)->minutes();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/user')->assertUnauthorized();
    }

    public function test_forgot_password_reply_is_identical_for_known_and_unknown_email(): void
    {
        Notification::fake();

        $known = $this->postJson('/api/password/forgot', ['email' => User::findOrFail(5)->email]);
        $unknown = $this->postJson('/api/password/forgot', ['email' => 'nobody-'.uniqid().'@example.com']);

        $known->assertOk();
        $unknown->assertOk();
        $this->assertSame($known->json(), $unknown->json());
    }

    public function test_password_reset_revokes_every_token(): void
    {
        $user = User::findOrFail(5);
        $user->createToken('vfrb-token');
        $user->createToken('vfrb-token');
        $resetToken = Password::createToken($user);

        $this->postJson('/api/password/reset', [
            'token' => $resetToken,
            'email' => $user->email,
            'password' => 'N3w-Passw0rd!',
            'password_confirmation' => 'N3w-Passw0rd!',
        ])->assertOk();

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_password_change_keeps_this_session_and_revokes_the_others(): void
    {
        $user = User::findOrFail(5);
        $user->forceFill(['password' => bcrypt('Old-Passw0rd!')])->save();
        $user->createToken('vfrb-token');
        $current = $user->createToken('vfrb-token');

        $this->withToken($current->plainTextToken)->putJson('/api/customer/profile/password', [
            'current_password' => 'Old-Passw0rd!',
            'password' => 'N3w-Passw0rd!',
            'password_confirmation' => 'N3w-Passw0rd!',
        ])->assertOk();

        $this->assertSame([$current->accessToken->id], $user->tokens()->pluck('id')->all());
    }

    public function test_recommend_materials_is_limited_to_ten_per_minute(): void
    {
        // Missing order_id fails validation, so no Gemini call is made; the throttle runs before it.
        $this->actingAs(User::findOrFail(5), 'sanctum');

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/customer/ai/recommend-materials', [])->assertStatus(422);
        }
        $this->postJson('/api/customer/ai/recommend-materials', [])->assertStatus(429);
    }

    public function test_localhost_origins_are_not_allowed_outside_local(): void
    {
        $this->assertNotContains('http://localhost:5173', config('cors.allowed_origins'));

        $this->call('OPTIONS', '/api/login', [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost:5173',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ])->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_email_verify_link_requires_a_valid_signature(): void
    {
        $user = User::findOrFail(5);
        $user->forceFill(['email_verified_at' => null])->save();
        $params = ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())];

        $this->get(route('verification.verify', $params))->assertForbidden();
        $this->get(URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), $params).'x')->assertForbidden();
        $this->assertNull($user->fresh()->email_verified_at);

        $this->get(URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), $params))
            ->assertRedirectContains('status=verified');
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_five_failed_logins_lock_that_account_on_this_ip_only(): void
    {
        $user = User::findOrFail(5);
        $user->forceFill(['password' => bcrypt('Right-Passw0rd!')])->save();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', ['login' => $user->email, 'password' => 'wrong'])->assertUnauthorized();
        }
        $this->postJson('/api/login', ['login' => $user->email, 'password' => 'Right-Passw0rd!'])->assertStatus(429);
        // Same account through the admin alias shares the lock; a different IP does not.
        $this->postJson('/api/admin/login', ['login' => strtoupper($user->email), 'password' => 'Right-Passw0rd!'])->assertStatus(429);
        $this->withServerVariables(['REMOTE_ADDR' => '10.9.9.9'])
            ->postJson('/api/login', ['login' => $user->email, 'password' => 'Right-Passw0rd!'])->assertOk();
    }

    public function test_successful_login_clears_the_failure_count(): void
    {
        $user = User::findOrFail(5);
        $user->forceFill(['password' => bcrypt('Right-Passw0rd!')])->save();

        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/login', ['login' => $user->email, 'password' => 'wrong'])->assertUnauthorized();
        }
        $this->postJson('/api/login', ['login' => $user->email, 'password' => 'Right-Passw0rd!'])->assertOk();
        $this->postJson('/api/login', ['login' => $user->email, 'password' => 'wrong'])->assertUnauthorized();
    }

    public function test_hsts_is_sent_on_https_only(): void
    {
        $this->get('https://localhost/up')->assertHeader('Strict-Transport-Security', 'max-age=31536000');
        $this->get('http://localhost/up')->assertHeaderMissing('Strict-Transport-Security');
    }
}
