<?php

namespace Tests\Feature;

use App\Http\Middleware\StationScope;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

// Calls every customer GET route as customer 5 and fails if a money, payment, QC or formula key appears anywhere in the JSON.
// Keys: StationScope::MONEY_KEYS + OrderController::clientMoney() + billing amounts + recommendation quantities + stage keys.
class CustomerPayloadSweepTest extends TestCase
{
    use DatabaseTransactions;

    private const EXTRA_FORBIDDEN = [
        'discount_pct', 'payment_terms', 'qc_required', 'qc_passed_at',
        'total_amount', 'amount', 'balance_after',
        'estimated_range', 'total_estimated_range', 'recommended_qty', 'quantity_needed',
        'stage', 'stages', 'new_stage', 'current_stage', 'assigned_stage', 'stage_progress', 'stage_total',
        'by_stage', 'output_by_stage', 'incomplete_stages', 'wastage',
    ];

    // The customer's own payment record (no amounts), shown on their receipts; allowed by owner decision 2026-11-02.
    private const ALLOWED = ['down_payment_received_at', 'payment_method', 'reference_no', 'paid_at'];

    public function test_no_customer_get_route_returns_a_forbidden_key(): void
    {
        $customer = User::findOrFail(5);
        $orderId = (int) DB::table('orders')->where('user_id', 5)->value('order_id');
        $this->assertGreaterThan(0, $orderId, 'Seed needs an order for customer 5.');
        [$invoiceId, $receiptId] = $this->seedRecords($orderId);

        $ids = ['{id}' => $orderId, '{orderId}' => $orderId];
        $byPrefix = ['api/customer/invoices/' => $invoiceId, 'api/customer/receipts/' => $receiptId];
        $forbidden = array_diff(array_merge(StationScope::MONEY_KEYS, self::EXTRA_FORBIDDEN), self::ALLOWED);

        $this->actingAs($customer, 'sanctum');
        $called = 0;
        $leaks = [];
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/customer/') || ! in_array('GET', $route->methods(), true)) {
                continue;
            }
            $url = $uri;
            foreach ($byPrefix as $prefix => $id) {
                if (str_starts_with($uri, $prefix)) {
                    $url = str_replace('{id}', (string) $id, $url);
                }
            }
            $url = '/'.strtr($url, array_map('strval', $ids));

            $response = $this->getJson($url);
            $this->assertLessThan(500, $response->status(), "$url returned {$response->status()}");
            $called++;
            if (! str_contains((string) $response->headers->get('Content-Type'), 'json')) {
                continue;
            }
            if (! empty($response->json('production_stages'))) {
                $leaks[] = "$url → production_stages (not empty)";
            }
            foreach ($this->keys($response->json()) as $key) {
                if (in_array($key, $forbidden, true)) {
                    $leaks[] = "$url → $key";
                }
            }
        }

        $this->assertGreaterThan(20, $called, 'Expected every customer GET route to be called.');
        $this->assertSame([], array_values(array_unique($leaks)), 'Forbidden keys in customer responses.');
    }

    // An invoice, receipt, message, delivery row and AI recommendation on the order, so nested shapes are present.
    private function seedRecords(int $orderId): array
    {
        $now = now();
        $invoiceId = DB::table('invoices')->insertGetId([
            'order_id' => $orderId, 'invoice_number' => 'INV-SWEEP-'.$orderId, 'billed_to_name' => 'Sweep',
            'total_amount' => 1000, 'status' => 'issued', 'issued_at' => $now, 'issued_by' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ], 'invoice_id');
        $receiptId = DB::table('payment_receipts')->insertGetId([
            'order_id' => $orderId, 'invoice_id' => $invoiceId, 'receipt_number' => 'OR-SWEEP-'.$orderId,
            'amount' => 500, 'payment_method' => 'cash', 'balance_after' => 500, 'paid_at' => $now, 'issued_by' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ], 'receipt_id');
        DB::table('order_messages')->insert([
            'order_id' => $orderId, 'sender_id' => 1, 'body' => 'Sweep', 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('delivery_tracking')->insert([
            'order_id' => $orderId, 'delivery_method' => 'pickup', 'delivery_status' => 'preparing',
            'updated_by' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('material_recommendations')->insert([
            'order_id' => $orderId, 'material_name' => 'Sweep cotton', 'created_at' => $now, 'updated_at' => $now,
        ]);

        return [$invoiceId, $receiptId];
    }

    private function keys(mixed $data): array
    {
        if (! is_array($data)) {
            return [];
        }
        $keys = [];
        foreach ($data as $k => $v) {
            if (is_string($k)) {
                $keys[] = $k;
            }
            array_push($keys, ...$this->keys($v));
        }

        return $keys;
    }
}
