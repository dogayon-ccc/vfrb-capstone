<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
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
}
