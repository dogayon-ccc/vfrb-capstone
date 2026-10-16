<?php
// tests/Feature/AiRecommendationFeedbackTest.php
// Staff accept/reject feedback on AI material recommendations (2026-10-16): who may give it, that it persists, that a reviewed list
// is never wiped by a regeneration, and that later recommendations for the same garment use it (prompt and catalog fallback).
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AiRecommendationFeedbackTest extends TestCase
{
    use DatabaseTransactions;

    // Fixture (vfrb_test): order 9 = customer 5, pending, polo_shirt, sky blue; order 8 = an earlier completed polo order.
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertNotSame('pgsql', DB::connection()->getDriverName(), 'Point DB_* at the local vfrb_test database.');
        config(['services.gemini.key_1' => 'test-key', 'services.gemini.key_2' => null, 'services.gemini.key_3' => null]);
    }

    private function makeUser(string $role): User
    {
        $user = User::create(['name' => ucfirst($role) . ' Test', 'email' => uniqid($role) . '@example.test', 'password' => bcrypt('secret-pass')]);
        $user->forceFill(['email_verified_at' => now()])->save();
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $user->assignRole($role);
        return $user;
    }

    // A recommended line on the earlier order, as staff left it.
    private function pastLine(int $materialId, ?string $verdict, string $garment = 'polo_shirt'): int
    {
        DB::table('orders')->where('order_id', 8)->update(['garment_type' => $garment]);
        $name = DB::table('materials')->where('material_id', $materialId)->value('material_name');
        return DB::table('material_recommendations')->insertGetId([
            'order_id' => 8, 'material_id' => $materialId, 'material_name' => $name, 'category' => 'Fabric', 'unit' => 'yards', 'status' => 'pending',
            'staff_feedback' => $verdict, 'staff_feedback_at' => $verdict ? now() : null, 'created_at' => now(), 'updated_at' => now(),
        ], 'rec_id');
    }

    private function recommend()
    {
        Sanctum::actingAs(User::findOrFail(5), ['*']);
        return $this->postJson('/api/customer/ai/recommend-materials', ['order_id' => 9]);
    }

    public function test_staff_and_managers_can_record_feedback_and_it_persists(): void
    {
        $rec = $this->pastLine(2, null);
        Sanctum::actingAs($this->makeUser('staff'), ['*']);
        $this->patchJson("/api/admin/material-recommendations/{$rec}/feedback", ['verdict' => 'reject', 'note' => 'Too heavy for this polo.'])
            ->assertOk()->assertJsonPath('data.staff_feedback', 'reject');
        $row = DB::table('material_recommendations')->where('rec_id', $rec)->first();
        $this->assertSame(['reject', 'Too heavy for this polo.'], [$row->staff_feedback, $row->staff_feedback_note]);
        $this->assertNotNull($row->staff_feedback_by);

        Sanctum::actingAs($this->makeUser('manager'), ['*']);
        $this->patchJson("/api/admin/material-recommendations/{$rec}/feedback", ['verdict' => 'clear'])->assertOk();
        $row = DB::table('material_recommendations')->where('rec_id', $rec)->first();
        $this->assertNull($row->staff_feedback);
        $this->assertNull($row->staff_feedback_by);
    }

    public function test_customers_and_the_read_only_owner_cannot_record_feedback(): void
    {
        $rec = $this->pastLine(2, null);
        Sanctum::actingAs(User::findOrFail(5), ['*']);
        $this->patchJson("/api/admin/material-recommendations/{$rec}/feedback", ['verdict' => 'reject'])->assertStatus(403);
        Sanctum::actingAs($this->makeUser('owner'), ['*']);
        $this->patchJson("/api/admin/material-recommendations/{$rec}/feedback", ['verdict' => 'reject'])->assertStatus(403);
        $this->assertNull(DB::table('material_recommendations')->where('rec_id', $rec)->value('staff_feedback'));
    }

    public function test_feedback_notes_never_carry_quantities_or_prices_and_verdicts_are_validated(): void
    {
        $rec = $this->pastLine(2, null);
        Sanctum::actingAs($this->makeUser('staff'), ['*']);
        $this->patchJson("/api/admin/material-recommendations/{$rec}/feedback", ['verdict' => 'reject', 'note' => 'Use 3 yards at PHP 200'])->assertStatus(422);
        $this->patchJson("/api/admin/material-recommendations/{$rec}/feedback", ['verdict' => 'maybe'])->assertStatus(422);
        $this->patchJson('/api/admin/material-recommendations/99999999/feedback', ['verdict' => 'accept'])->assertStatus(404);
    }

    public function test_feedback_on_the_same_garment_reaches_the_next_prompt_with_names_compared_normalised(): void
    {
        $this->pastLine(2, 'reject', 'Polo Shirt');   // earlier order spelled differently from order 9's "polo_shirt"
        $this->pastLine(12, 'accept', 'Polo Shirt');
        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode(['selections' => [['material_id' => 12, 'reason' => 'ok']], 'narration' => 'ok'])]]]]]])]);

        $this->recommend()->assertOk();

        Http::assertSent(function (Request $r) {
            $prompt = $r->data()['contents'][0]['parts'][0]['text'];
            return str_contains($prompt, 'rejected for this garment on earlier orders (avoid unless the design clearly needs them): Cotton-Poly Blend Fabric (Navy)')
                && str_contains($prompt, 'approved for this garment on earlier orders (good candidates when they fit this design): Cotton Fabric');
        });
    }

    public function test_the_latest_verdict_wins_and_other_garments_do_not_count(): void
    {
        $old = $this->pastLine(2, 'reject');
        DB::table('material_recommendations')->where('rec_id', $old)->update(['staff_feedback_at' => now()->subDay()]);
        $this->pastLine(2, 'accept');                 // later: accepted after all
        $scrub = DB::table('material_recommendations')->insertGetId([
            'order_id' => 2, 'material_id' => 12, 'material_name' => 'Cotton Fabric', 'category' => 'Fabric', 'unit' => 'yards', 'status' => 'pending',
            'staff_feedback' => 'reject', 'staff_feedback_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ], 'rec_id');                                  // a scrub-top order: says nothing about polos
        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode(['selections' => [['material_id' => 2, 'reason' => 'ok']], 'narration' => 'ok'])]]]]]])]);

        $this->recommend()->assertOk();

        Http::assertSent(fn (Request $r) => str_contains($p = $r->data()['contents'][0]['parts'][0]['text'], '(avoid unless the design clearly needs them): none')
            && str_contains($p, '(good candidates when they fit this design): Cotton-Poly Blend Fabric (Navy)'));
        $this->assertGreaterThan(0, $scrub);
    }

    public function test_the_catalog_fallback_follows_feedback_too(): void
    {
        config(['services.gemini.key_1' => null]); // deterministic path, no provider call
        Http::fake();

        // Without feedback the nearest colour (navy, id 2) is the main fabric for this sky-blue polo.
        $this->assertContains(2, collect($this->recommend()->assertOk()->json('materials'))->pluck('material_id')->all());
        DB::table('material_recommendations')->where('order_id', 9)->delete();

        $this->pastLine(2, 'reject');
        $this->pastLine(12, 'accept');
        $ids = collect($this->recommend()->assertOk()->assertJsonPath('source', 'catalog_default')->json('materials'))->pluck('material_id')->all();
        $this->assertNotContains(2, $ids);
        $this->assertContains(12, $ids);
        Http::assertNothingSent();
    }

    public function test_a_reviewed_list_is_locked_so_a_regeneration_never_wipes_staff_feedback(): void
    {
        DB::table('material_recommendations')->insert([
            'order_id' => 9, 'material_id' => 2, 'material_name' => 'Cotton-Poly Blend Fabric (Navy)', 'category' => 'Fabric', 'unit' => 'yards',
            'status' => 'pending', 'staff_feedback' => 'reject', 'staff_feedback_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        Http::fake();

        $this->recommend()->assertStatus(409);
        Http::assertNothingSent();
        $this->assertSame('reject', DB::table('material_recommendations')->where('order_id', 9)->value('staff_feedback'));
    }
}
