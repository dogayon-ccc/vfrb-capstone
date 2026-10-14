<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use LogicException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

// Insert-only audit_logs. LOCAL database only (rolled back per test); needs the
// 2026_10_16_000001 migration applied to vfrb_test first.
//   DB_CONNECTION=mysql DB_DATABASE=vfrb_test php artisan test --filter AuditLogTest
class AuditLogTest extends TestCase
{
    use DatabaseTransactions;

    private int $baseline;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertNotSame('pgsql', DB::connection()->getDriverName(), 'Point DB_* at the local vfrb_test database.');
        $this->baseline = (int) DB::table('audit_logs')->max('audit_id');
    }

    private function makeUser(string $role): User
    {
        $user = User::create(['name' => ucfirst($role) . ' Test', 'email' => uniqid($role) . '@example.test', 'password' => bcrypt('secret-pass')]);
        $user->forceFill(['email_verified_at' => now()])->save();
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $user->assignRole($role);
        return $user;
    }

    private function newRows()
    {
        return DB::table('audit_logs')->where('audit_id', '>', $this->baseline)->orderBy('audit_id')->get();
    }

    public function test_login_failed_login_and_logout_are_recorded(): void
    {
        $user = $this->makeUser('staff');

        $this->postJson('/api/login', ['login' => $user->email, 'password' => 'wrong-pass'])->assertStatus(401);
        $this->postJson('/api/login', ['login' => 'nobody-' . uniqid() . '@example.test', 'password' => 'x'])->assertStatus(401);
        $token = $this->postJson('/api/login', ['login' => $user->email, 'password' => 'secret-pass'])->assertOk()->json('token');

        $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/logout')->assertOk();

        $rows = $this->newRows();
        $this->assertSame(['login_failed', 'login_failed', 'login', 'logout'], $rows->pluck('action')->all());
        $this->assertEquals([$user->user_id, null, $user->user_id, $user->user_id], $rows->pluck('user_id')->all());
        $this->assertNotNull($rows->first()->ip);
    }

    public function test_a_key_write_is_recorded_against_its_actor(): void
    {
        $manager = $this->makeUser('manager');

        $this->actingAs($manager, 'sanctum')->patchJson('/api/admin/settings/company', [])->assertOk();

        $row = $this->newRows()->last();
        $this->assertSame('settings_updated', $row->action);
        $this->assertSame('company_settings', $row->entity);
        $this->assertEquals($manager->user_id, $row->user_id);
    }

    public function test_only_manager_and_owner_can_read_and_nobody_can_write(): void
    {
        $this->actingAs($this->makeUser('staff'), 'sanctum')->getJson('/api/admin/audit-logs')->assertStatus(403);
        $this->actingAs($this->makeUser('customer'), 'sanctum')->getJson('/api/admin/audit-logs')->assertStatus(403);

        $this->actingAs($this->makeUser('manager'), 'sanctum')->getJson('/api/admin/audit-logs')->assertOk();

        $owner = $this->makeUser('owner');
        $this->actingAs($owner, 'sanctum')->getJson('/api/admin/audit-logs?action=login')->assertOk()
            ->assertJsonStructure(['data', 'actions', 'current_page', 'last_page', 'total', 'per_page']);
        $this->actingAs($owner, 'sanctum')->postJson('/api/admin/audit-logs', ['action' => 'login'])->assertStatus(405);
    }

    public function test_rows_cannot_be_updated_or_deleted(): void
    {
        AuditLog::record('login', userId: 1);
        $row = AuditLog::where('audit_id', '>', $this->baseline)->firstOrFail();

        $this->assertThrows(fn () => $row->update(['action' => 'logout']), LogicException::class);
        $this->assertThrows(fn () => $row->delete(), LogicException::class);

        // Raw queries skip the model; the database triggers refuse them.
        $this->assertThrows(fn () => DB::table('audit_logs')->where('audit_id', $row->audit_id)->update(['action' => 'logout']), QueryException::class);
        $this->assertThrows(fn () => DB::table('audit_logs')->where('audit_id', $row->audit_id)->delete(), QueryException::class);
    }
}
