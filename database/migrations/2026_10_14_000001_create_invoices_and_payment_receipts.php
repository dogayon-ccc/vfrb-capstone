<?php
// Additive only: two new tables + nullable company_settings columns. Nothing is dropped or renamed.
//   invoices          one active (non-void) invoice per order, billed-to details frozen at issue time
//   payment_receipts  one receipt per payment; sales_transactions keeps its cumulative row as before
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id('invoice_id');
            $table->foreignId('order_id')->constrained('orders', 'order_id')->restrictOnDelete();
            $table->string('invoice_number', 20)->unique();               // INV-2026-0001
            $table->string('status', 10)->default('issued');              // issued | paid | void
            $table->foreignId('billing_profile_id')->nullable()->constrained('billing_profiles', 'billing_id')->nullOnDelete();
            // Snapshot: later profile edits must not change an issued invoice.
            $table->string('billed_to_name', 150);
            $table->string('billed_to_tin', 30)->nullable();
            $table->string('billed_to_address', 255)->nullable();
            $table->string('billed_to_email', 150)->nullable();
            $table->decimal('total_amount', 12, 2);
            $table->date('due_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('issued_at');
            $table->foreignId('issued_by')->constrained('users', 'user_id')->restrictOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('marked_paid_by')->nullable()->constrained('users', 'user_id')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();
        });
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_status_check CHECK (status IN ('issued','paid','void'))");
        // A voided invoice can be reissued; only one live invoice per order.
        DB::statement("CREATE UNIQUE INDEX invoices_order_active_unique ON invoices (order_id) WHERE status <> 'void'");

        Schema::create('payment_receipts', function (Blueprint $table) {
            $table->id('receipt_id');
            $table->foreignId('order_id')->constrained('orders', 'order_id')->restrictOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices', 'invoice_id')->restrictOnDelete();
            $table->string('receipt_number', 20)->unique();               // RCT-2026-0001 (not "OR": these are not BIR official receipts)
            $table->decimal('amount', 12, 2);
            $table->string('payment_method', 20);
            $table->string('reference_no', 50)->nullable();               // GCash / bank ref
            $table->decimal('balance_after', 12, 2);
            $table->timestamp('paid_at');
            $table->foreignId('issued_by')->constrained('users', 'user_id')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['order_id', 'paid_at']);
        });
        DB::statement("ALTER TABLE payment_receipts ADD CONSTRAINT payment_receipts_method_check CHECK (payment_method IN ('cash','gcash','ewallet','bank_transfer'))");
        DB::statement('ALTER TABLE payment_receipts ADD CONSTRAINT payment_receipts_amount_check CHECK (amount > 0)');

        Schema::table('company_settings', function (Blueprint $table) {
            $table->string('tin', 30)->nullable()->after('contact_email');
            $table->string('invoice_terms', 500)->nullable()->after('tin');  // with tin, switches documents to registered titles
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', fn (Blueprint $t) => $t->dropColumn(['tin', 'invoice_terms']));
        Schema::dropIfExists('payment_receipts');
        Schema::dropIfExists('invoices');
    }
};
