<?php

namespace Tests\Feature;

use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\OrderStatusNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Customer "order confirmed / cancelled" emails from every path that sends them. LOCAL database only (rolled back per test).
// Users: 1 = manager, 5 = customer.
class OrderStatusEmailTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertNotSame('pgsql', DB::connection()->getDriverName(), 'Point DB_* at the local vfrb_test database.');
        Notification::fake();
        $this->withoutDefer();
        Sanctum::actingAs(User::findOrFail(1), ['*']);
    }

    private function order(string $status, array $over = []): int
    {
        return DB::table('orders')->insertGetId(array_merge([
            'user_id' => 5, 'garment_type' => 'Polo Shirt', 'color' => 'Navy', 'quantity_ordered' => 100, 'order_type' => 'direct',
            'status' => $status, 'qc_required' => 0, 'agreed_total' => 50000, 'promised_date' => now()->addDays(14)->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ], $over), 'order_id');
    }

    private function mailFor(int $orderId): array
    {
        return Notification::sent(User::find(5), OrderStatusNotification::class)
            ->map(fn ($n) => $n->toMail(User::find(5)))
            ->filter(fn ($mail) => str_contains($mail->subject, "#{$orderId}"))
            ->values()->all();
    }

    public function test_confirm_from_order_detail_emails_the_customer_once_with_a_link_to_the_order(): void
    {
        $id = $this->order('pending');

        $this->patchJson("/api/admin/orders/{$id}", ['status' => 'confirmed'])->assertOk();

        $mails = $this->mailFor($id);
        $this->assertCount(1, $mails);
        $this->assertSame("Your VFRB Order #{$id} Has Been Confirmed", $mails[0]->subject);
        $this->assertSame("/orders/{$id}", parse_url($mails[0]->actionUrl, PHP_URL_PATH));
        $this->assertTrue(DB::table('notifications')->where(['order_id' => $id, 'user_id' => 5])
            ->where('message', "Your order #{$id} status has been updated to Confirmed.")->exists());
    }

    public function test_cancel_email_carries_the_managers_reason(): void
    {
        $id = $this->order('confirmed');

        $this->patchJson("/api/admin/orders/{$id}", ['status' => 'cancelled', 'notes' => 'Fabric no longer available.'])->assertOk();

        $mails = $this->mailFor($id);
        $this->assertCount(1, $mails);
        $this->assertSame("Update on Your VFRB Order #{$id}", $mails[0]->subject);
        $this->assertContains('Reason given: Fabric no longer available.', $mails[0]->introLines);
    }

    public function test_quick_confirm_and_customer_accepting_a_proposal_also_email_the_customer(): void
    {
        $quick = $this->order('pending');
        $this->patchJson("/api/admin/orders/{$quick}/confirm")->assertOk();

        $proposal = $this->order('pending', ['proposal_status' => 'pending']);
        Sanctum::actingAs(User::findOrFail(5), ['*']);
        $this->postJson("/api/customer/orders/{$proposal}/accept")->assertOk();

        $this->assertCount(1, $this->mailFor($quick));
        $this->assertCount(1, $this->mailFor($proposal));
    }

    public function test_every_confirmed_piece_of_a_uniform_set_gets_its_own_email(): void
    {
        $setId = 'set-'.uniqid();
        $config = fn (string $garment) => json_encode(['garment' => $garment, 'uniformSet' => ['id' => $setId, 'role' => 'top', 'part' => 1, 'of' => 2]]);
        $top = $this->order('pending', ['studio_config' => $config('Polo Shirt')]);
        $bottom = $this->order('pending', ['studio_config' => $config('Pants'), 'garment_type' => 'Pants']);

        $this->patchJson("/api/admin/orders/{$top}/confirm")->assertOk();

        $this->assertCount(1, $this->mailFor($top));
        $this->assertCount(1, $this->mailFor($bottom));
    }

    public function test_refused_stage_moves_and_an_unchanged_status_send_no_status_email(): void
    {
        $sewing = $this->order('cutting', ['down_payment_received_at' => now()]);
        $done = $this->order('for_delivery', ['down_payment_received_at' => now()]);
        $same = $this->order('confirmed');

        // This endpoint refuses manual production-stage and completion moves.
        $this->patchJson("/api/admin/orders/{$sewing}", ['status' => 'sewing'])->assertStatus(422);
        $this->patchJson("/api/admin/orders/{$done}", ['status' => 'completed'])->assertStatus(422);
        $this->patchJson("/api/admin/orders/{$same}", ['status' => 'confirmed'])->assertOk();

        $this->assertSame([], array_merge($this->mailFor($sewing), $this->mailFor($done), $this->mailFor($same)));
    }

    public function test_customer_with_email_turned_off_gets_no_mail(): void
    {
        NotificationPreference::updateOrCreate(['user_id' => 5], ['email_enabled' => false]);
        $id = $this->order('pending');

        $this->patchJson("/api/admin/orders/{$id}", ['status' => 'confirmed'])->assertOk();

        $this->assertSame([], (new OrderStatusNotification($id, 'confirmed'))->via(User::find(5)));
        Notification::assertNotSentTo(User::find(5), OrderStatusNotification::class,
            fn ($n, $channels) => $channels !== []);
    }
}
