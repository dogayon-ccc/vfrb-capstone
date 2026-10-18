<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Order proposal flow: admin proposes date + total, customer accepts or declines, production waits for the down payment.
// Each column is added only if missing; down_payment_amount predates this migration on most databases.
return new class extends Migration {
    private const COLUMNS = [
        'promised_date', 'proposal_note', 'proposal_status', 'fabric_type',
        'delivery_address', 'down_payment_amount', 'down_payment_received_at',
    ];

    public function up(): void {
        $missing = array_values(array_filter(self::COLUMNS, fn ($c) => !Schema::hasColumn('orders', $c)));

        Schema::table('orders', function (Blueprint $t) use ($missing) {
            $add = fn ($c) => in_array($c, $missing, true);
            if ($add('promised_date'))            $t->date('promised_date')->nullable();
            if ($add('proposal_note'))            $t->text('proposal_note')->nullable();
            if ($add('proposal_status'))          $t->string('proposal_status', 20)->nullable(); // pending|accepted|declined
            if ($add('fabric_type'))              $t->string('fabric_type', 100)->nullable();
            if ($add('delivery_address'))         $t->text('delivery_address')->nullable();
            if ($add('down_payment_amount'))      $t->decimal('down_payment_amount', 10, 2)->nullable();
            if ($add('down_payment_received_at')) $t->timestamp('down_payment_received_at')->nullable();
        });

        // The customer no longer has to give a delivery date; VFRB proposes promised_date instead.
        if (Schema::hasColumn('orders', 'target_delivery_date')) {
            Schema::table('orders', fn (Blueprint $t) => $t->date('target_delivery_date')->nullable()->change());
        }
    }

    public function down(): void {
        // down_payment_amount is left alone: it existed before this migration.
        $drop = array_values(array_filter(
            array_diff(self::COLUMNS, ['down_payment_amount']),
            fn ($c) => Schema::hasColumn('orders', $c)
        ));
        if ($drop) {
            Schema::table('orders', fn (Blueprint $t) => $t->dropColumn($drop));
        }
    }
};
