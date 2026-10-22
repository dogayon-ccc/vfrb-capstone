<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Auto receipt on down payment, auto invoice on delivery. LOCAL database only (rolled back per test). Users: 1 = manager, 4 = staff, 5 = customer.
class AutoBillingTest extends TestCase
{
    use DatabaseTransactions;

    private const STAGES = ['pattern', 'segregation', 'cutting', 'sewing', 'qc', 'pressing', 'packing'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertNotSame('pgsql', DB::connection()->getDriverName(), 'Point DB_* at the local vfrb_test database.');
        Sanctum::actingAs(User::findOrFail(1), ['*']);
    }

    private function order(string $status, array $over = []): int
    {
        return DB::table('orders')->insertGetId(array_merge([
            'user_id' => 5, 'garment_type' => 'Polo Shirt', 'color' => 'Navy', 'quantity_ordered' => 100,
            'order_type' => 'direct', 'status' => $status, 'qc_required' => 0, 'created_at' => now(), 'updated_at' => now(),
            'promised_date' => now()->addDays(14)->toDateString(), 'agreed_total' => 50000,
        ], $over), 'order_id');
    }

    private function packedWithDelivery(int $orderId): int
    {
        foreach (self::STAGES as $s) {
            DB::table('order_production_tracking')->insert([
                'order_id' => $orderId, 'stage' => $s, 'qty_target' => 100, 'qty_completed' => 100,
                'updated_by' => 4, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('daily_output_logs')->insert([
                'order_id' => $orderId, 'stage' => $s, 'logged_by' => 4, 'qty_m' => 100,
                'log_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return DB::table('delivery_tracking')->insertGetId([
            'order_id' => $orderId, 'delivery_method' => 'pickup', 'delivery_status' => 'preparing',
            'updated_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ], 'tracking_id');
    }

    public function test_down_payment_writes_one_receipt_and_counts_toward_the_balance(): void
    {
        $id = $this->order('confirmed');

        $this->postJson("/api/admin/orders/{$id}/down-payment", ['down_payment_amount' => 40000, 'payment_method' => 'gcash'])->assertOk();

        $receipt = DB::table('payment_receipts')->where('order_id', $id)->first();
        $this->assertSame([40000.0, 'gcash', 10000.0], [(float) $receipt->amount, $receipt->payment_method, (float) $receipt->balance_after]);
        $this->assertSame(40000.0, (float) DB::table('sales_transactions')->where('order_id', $id)->value('amount_paid'));
    }

    public function test_down_payment_without_a_method_defaults_to_cash(): void
    {
        $id = $this->order('confirmed');

        $this->postJson("/api/admin/orders/{$id}/down-payment", ['down_payment_amount' => 40000])->assertOk();

        $this->assertSame('cash', DB::table('payment_receipts')->where('order_id', $id)->value('payment_method'));
    }

    public function test_down_payment_already_logged_as_a_transaction_is_not_receipted_twice(): void
    {
        $id = $this->order('confirmed');
        $this->postJson('/api/admin/transactions', ['order_id' => $id, 'amount_paid' => 40000, 'payment_method' => 'cash', 'payment_terms' => 'down_payment'])
            ->assertSuccessful();

        $this->postJson("/api/admin/orders/{$id}/down-payment", ['down_payment_amount' => 40000])->assertOk();

        $this->assertSame(1, DB::table('payment_receipts')->where('order_id', $id)->count());
        $this->assertSame(40000.0, (float) DB::table('sales_transactions')->where('order_id', $id)->value('amount_paid'));
    }

    public function test_marking_delivered_issues_the_invoice_and_links_earlier_receipts(): void
    {
        $id = $this->order('confirmed');
        $this->postJson("/api/admin/orders/{$id}/down-payment", ['down_payment_amount' => 40000])->assertOk();
        DB::table('orders')->where('order_id', $id)->update(['status' => 'packing']);
        $t = $this->packedWithDelivery($id);

        $this->patchJson("/api/admin/delivery/{$t}/delivered")->assertOk();

        $invoice = DB::table('invoices')->where('order_id', $id)->first();
        $this->assertSame(['issued', 50000.0], [$invoice->status, (float) $invoice->total_amount]);
        $this->assertSame((int) $invoice->invoice_id, (int) DB::table('payment_receipts')->where('order_id', $id)->value('invoice_id'));
        $this->assertTrue(DB::table('notifications')->where('order_id', $id)->where('user_id', 5)
            ->where('message', 'like', "{$invoice->invoice_number}%")->exists());
    }

    public function test_delivery_keeps_an_existing_invoice(): void
    {
        $id = $this->order('packing', ['order_type' => 'subcontract']);
        $t = $this->packedWithDelivery($id);
        $this->postJson("/api/admin/orders/{$id}/invoice")->assertCreated();

        $this->patchJson("/api/admin/delivery/{$t}/delivered")->assertOk();

        $this->assertSame(1, DB::table('invoices')->where('order_id', $id)->count());
    }

    public function test_delivery_without_an_agreed_total_issues_no_invoice(): void
    {
        $id = $this->order('packing', ['order_type' => 'subcontract', 'agreed_total' => null]);
        $t = $this->packedWithDelivery($id);

        $this->patchJson("/api/admin/delivery/{$t}/delivered")->assertOk();

        $this->assertSame('completed', DB::table('orders')->where('order_id', $id)->value('status'));
        $this->assertSame(0, DB::table('invoices')->where('order_id', $id)->count());
    }
}
