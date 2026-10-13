<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Sms\SmsDriver;
use App\Support\SmsNotify;
use App\Support\UploadDisk;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Profile photo, SMS and appearance. LOCAL database only (rolled back per test); the upload disk is faked, so nothing reaches Cloudinary.
//   DB_CONNECTION=mysql DB_DATABASE=vfrb_test php artisan test --filter ProfilePhotoSmsAppearanceTest
// Users: 1 = manager, 4 = staff, 5 and 6 = customers.
class ProfilePhotoSmsAppearanceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertNotSame('pgsql', DB::connection()->getDriverName(), 'Point DB_* at the local vfrb_test database.');
        Storage::fake(UploadDisk::name());
    }

    private function actAs(int $userId): void
    {
        Sanctum::actingAs(User::findOrFail($userId), ['*']);
    }

    // ── Profile photo ────────────────────────────────────────────────────────

    public function test_every_role_can_upload_their_own_photo_under_a_random_name(): void
    {
        foreach ([1, 4, 5] as $id) {
            $this->actAs($id);
            $this->post('/api/profile/photo', ['photo' => UploadedFile::fake()->image('../../evil name.png', 200, 200)], ['Accept' => 'application/json'])
                ->assertCreated()->assertJsonStructure(['avatar']);

            $path = DB::table('users')->where('user_id', $id)->value('avatar');
            $this->assertStringStartsWith("avatars/{$id}/", $path);
            $this->assertStringNotContainsString('evil', $path);
            Storage::disk(UploadDisk::name())->assertExists($path);
        }
    }

    public function test_non_images_oversized_and_tiny_files_are_rejected(): void
    {
        $this->actAs(5);
        $before = DB::table('users')->where('user_id', 5)->value('avatar');
        $bad = [
            UploadedFile::fake()->create('photo.svg', 5, 'image/svg+xml'),
            UploadedFile::fake()->create('photo.pdf', 5, 'application/pdf'),
            UploadedFile::fake()->create('photo.png', 10, 'text/plain'),   // wrong content behind an image name
            UploadedFile::fake()->image('big.jpg', 300, 300)->size(3000),  // over 2 MB
            UploadedFile::fake()->image('tiny.png', 10, 10),               // under 64 px
        ];
        foreach ($bad as $file) {
            $this->post('/api/profile/photo', ['photo' => $file], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('photo');
        }
        $this->assertSame($before, DB::table('users')->where('user_id', 5)->value('avatar'));
    }

    public function test_replacing_or_removing_only_touches_your_own_file(): void
    {
        $disk = Storage::disk(UploadDisk::name());
        $this->actAs(6);
        $this->post('/api/profile/photo', ['photo' => UploadedFile::fake()->image('a.png', 100, 100)], ['Accept' => 'application/json'])->assertCreated();
        $theirs = DB::table('users')->where('user_id', 6)->value('avatar');

        // User 5 points at user 6's file, then replaces and removes their own photo: user 6's file survives.
        DB::table('users')->where('user_id', 5)->update(['avatar' => $theirs]);
        $this->actAs(5);
        $this->post('/api/profile/photo', ['photo' => UploadedFile::fake()->image('b.png', 100, 100)], ['Accept' => 'application/json'])->assertCreated();
        $mine = DB::table('users')->where('user_id', 5)->value('avatar');
        $this->deleteJson('/api/profile/photo')->assertOk()->assertJson(['avatar' => null]);

        $disk->assertExists($theirs);
        $disk->assertMissing($mine);
        $this->assertNull(DB::table('users')->where('user_id', 5)->value('avatar'));
    }

    public function test_a_google_avatar_url_is_returned_as_is_and_cleared_without_a_storage_delete(): void
    {
        $this->post('/api/profile/photo', ['photo' => UploadedFile::fake()->image('a.png', 100, 100)], ['Accept' => 'application/json'])->assertUnauthorized();

        DB::table('users')->where('user_id', 5)->update(['avatar' => 'https://lh3.googleusercontent.com/a/x']);
        $this->actAs(5);
        $this->getJson('/api/user')->assertOk()->assertJsonPath('avatar', 'https://lh3.googleusercontent.com/a/x');
        $this->postJson('/api/profile/photo')->assertStatus(422)->assertJsonValidationErrors('photo');
        $this->deleteJson('/api/profile/photo')->assertOk();
        $this->assertNull(DB::table('users')->where('user_id', 5)->value('avatar'));
    }

    // ── SMS ──────────────────────────────────────────────────────────────────

    private function fakeDriver(): object
    {
        $fake = new class implements SmsDriver {
            public array $sent = [];
            public function send(string $to, string $message): void { $this->sent[] = [$to, $message]; }
            public function name(): string { return 'fake'; }
        };
        $this->app->instance(SmsDriver::class, $fake);
        return $fake;
    }

    public function test_ph_numbers_normalize_and_mask(): void
    {
        $this->assertSame('+639171234567', SmsNotify::e164('0917 123-4567'));
        $this->assertSame('+639171234567', SmsNotify::e164('+63 917 123 4567'));
        foreach (['', null, '12345', '+15551234567', '0817 123 4567', '029171234567'] as $bad) {
            $this->assertNull(SmsNotify::e164($bad));
        }
        $this->assertSame('+63917****567', SmsNotify::mask('+639171234567'));
    }

    public function test_opt_in_is_refused_while_sms_is_off_and_without_a_ph_mobile(): void
    {
        $this->actAs(5);
        config(['services.sms.enabled' => false]);
        $this->patchJson('/api/settings/notifications', ['sms_enabled' => true])->assertStatus(422)->assertJsonValidationErrors('sms_enabled');
        $this->getJson('/api/settings/notifications')->assertJson(['sms_provider_live' => false]);

        config(['services.sms.enabled' => true]);
        DB::table('users')->where('user_id', 5)->update(['contact_number' => '12345']);
        $this->patchJson('/api/settings/notifications', ['sms_enabled' => true])->assertStatus(422);
        $this->putJson('/api/customer/notification-preferences', ['email_enabled' => true, 'sms_enabled' => true])->assertStatus(422);

        DB::table('users')->where('user_id', 5)->update(['contact_number' => '09171234567']);
        $this->patchJson('/api/settings/notifications', ['sms_enabled' => true])->assertOk();
        $this->assertTrue((bool) DB::table('notification_preferences')->where('user_id', 5)->value('sms_enabled'));
        $this->patchJson('/api/settings/notifications', ['sms_enabled' => false])->assertOk(); // turning off always works
    }

    public function test_sends_only_to_opted_in_valid_numbers_and_logs_without_secrets(): void
    {
        $fake = $this->fakeDriver();
        config(['services.sms.enabled' => true, 'services.sms.token' => 'SECRET-TOKEN-123']);
        DB::table('users')->where('user_id', 5)->update(['contact_number' => '0917 123 4567']);
        DB::table('users')->where('user_id', 6)->update(['contact_number' => 'not a number']);
        foreach ([5 => 1, 6 => 1, 1 => 0] as $id => $on) {
            DB::table('notification_preferences')->updateOrInsert(['user_id' => $id], ['sms_enabled' => $on, 'email_enabled' => 1]);
        }

        $logged = [];
        Log::listen(function ($e) use (&$logged) { $logged[] = $e->message . ' ' . json_encode($e->context); });
        SmsNotify::sendNow([5, 6, 1], 'new_message', 'VFRB: hello');

        $this->assertSame([['+639171234567', 'VFRB: hello']], $fake->sent);
        $all = implode("\n", $logged);
        $this->assertStringContainsString('sms.sent', $all);
        $this->assertStringContainsString('+63917****567', $all);
        $this->assertSame(2, substr_count($all, 'sms.skipped'));
        $this->assertStringNotContainsString('SECRET-TOKEN-123', $all);
        $this->assertStringNotContainsString('09171234567', $all);
        $this->assertStringNotContainsString('VFRB: hello', $all);
    }

    public function test_nothing_is_sent_while_sms_is_off(): void
    {
        $fake = $this->fakeDriver();
        config(['services.sms.enabled' => false]);
        DB::table('users')->where('user_id', 5)->update(['contact_number' => '09171234567']);
        DB::table('notification_preferences')->updateOrInsert(['user_id' => 5], ['sms_enabled' => 1, 'email_enabled' => 1]);

        SmsNotify::users([5], 'status_changed', 'x');
        $this->assertSame([], $fake->sent);
    }

    public function test_status_texts_follow_the_customer_label_not_internal_stages(): void
    {
        $fake = $this->fakeDriver();
        config(['services.sms.enabled' => true]);
        DB::table('users')->where('user_id', 5)->update(['contact_number' => '09171234567']);
        DB::table('notification_preferences')->updateOrInsert(['user_id' => 5], ['sms_enabled' => 1, 'email_enabled' => 1]);

        $this->withoutDefer(); // send inline instead of after the response
        SmsNotify::orderStatus(9, 5, 'cutting', 'sewing');   // both "In Production": silent
        SmsNotify::orderStatus(9, 5, 'confirmed', 'pattern');

        $this->assertSame([['+639171234567', 'VFRB: Your order #9 is now In Production.']], $fake->sent);
    }

    // ── Appearance ───────────────────────────────────────────────────────────

    public function test_appearance_is_saved_per_user_and_validated(): void
    {
        if (!Schema::hasColumn('notification_preferences', 'theme_mode')) {
            $this->markTestSkipped('Run the 2026_10_15_000001 theme migration on this database first.');
        }
        $this->actAs(5);
        $this->patchJson('/api/settings/appearance', ['theme_mode' => 'dark', 'theme_accent' => 'navy'])->assertOk()
            ->assertJson(['theme_mode' => 'dark', 'theme_accent' => 'navy']);
        $this->patchJson('/api/settings/appearance', ['theme_mode' => 'neon'])->assertStatus(422);
        $this->patchJson('/api/settings/appearance', ['theme_accent' => '#ff00ff'])->assertStatus(422);
        $this->getJson('/api/user')->assertJsonPath('appearance.theme_accent', 'navy');

        $this->actAs(1);
        $this->getJson('/api/settings/appearance')->assertOk()->assertJson(['theme_accent' => null]); // another user's choice never leaks
    }
}
