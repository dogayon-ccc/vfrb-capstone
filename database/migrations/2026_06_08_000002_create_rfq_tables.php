<?php
// database/migrations/2026_06_08_000002_create_rfq_tables.php
// TASK N — RFQ full flow (Month 3)
//
// rfq_requests:  rfq_id, material_id, qty_needed, needed_by_date, status, created_by
// rfq_responses: response_id, rfq_id, supplier_id, unit_price, qty_available,
//                lead_time_days, notes, responded_at, logged_by, selected_for_po
//
// Columns match existing RfqRequest + RfqResponse model fillable exactly.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('rfq_requests')) {
            Schema::create('rfq_requests', function (Blueprint $table) {
                $table->id('rfq_id');
                $table->unsignedBigInteger('material_id');
                $table->foreign('material_id')->references('material_id')->on('materials')->cascadeOnDelete();
                $table->decimal('qty_needed', 10, 2);
                $table->date('needed_by_date')->nullable();
                $table->enum('status', ['open', 'closed'])->default('open');
                $table->unsignedBigInteger('created_by');
                $table->foreign('created_by')->references('user_id')->on('users')->restrictOnDelete();
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('rfq_responses')) {
            Schema::create('rfq_responses', function (Blueprint $table) {
                $table->id('response_id');
                $table->unsignedBigInteger('rfq_id');
                $table->foreign('rfq_id')->references('rfq_id')->on('rfq_requests')->cascadeOnDelete();
                $table->unsignedBigInteger('supplier_id');
                $table->foreign('supplier_id')->references('supplier_id')->on('suppliers')->restrictOnDelete();
                $table->decimal('unit_price', 10, 2)->nullable();
                $table->decimal('qty_available', 10, 2)->nullable();
                $table->unsignedInteger('lead_time_days')->nullable()
                      ->comment('Number of days supplier needs to deliver');
                $table->text('notes')->nullable();
                $table->timestamp('responded_at')->nullable();
                $table->unsignedBigInteger('logged_by')->nullable();
                $table->foreign('logged_by')->references('user_id')->on('users')->nullOnDelete();
                $table->boolean('selected_for_po')->default(false);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rfq_responses');
        Schema::dropIfExists('rfq_requests');
    }
};
