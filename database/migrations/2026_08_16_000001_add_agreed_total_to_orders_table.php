<?php
// database/migrations/2026_08_16_000001_add_agreed_total_to_orders_table.php
// BUG-005 — Payment balance always reads ₱0 / "Fully Paid"
//
// VFRB has NO fixed pricing engine — every order total is a manually
// negotiated deal between Ma'am Fe/Roxanne and the client (interview,
// Apr 30 2026). This column is just a place to hold that agreed number
// once staff records it, so subsequent partial payments on the same
// order can compute a real running balance instead of defaulting to 0.
//
// Confirmed via code inspection (not guessed): `orders` had no total-like
// column before this. The only "total_amount" field in the codebase
// belongs to `purchase_orders` (a different entity — supplier RFQs), and
// SalesTransactions.jsx was reading `orderInfo.total_amount` against
// customer orders, which never existed — that's the root of the always-
// ₱0-balance bug together with the SalesTransactionController fix below.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'agreed_total')) {
                $table->decimal('agreed_total', 10, 2)->nullable()->after('discount_pct')
                      ->comment('Negotiated order total (manual, no pricing engine). Set once on the first sales_transactions payment, read-only after.');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumnIfExists('agreed_total');
        });
    }
};
