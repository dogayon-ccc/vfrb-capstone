<?php
// database/migrations/2026_08_25_000001_create_production_incidents_table.php
//
// NEW TABLE — production_incidents
//
// Grounded directly in VFRB_MaamFe_Interview_Transcript_Apr30.docx, two
// real workflows that had no system equivalent before this migration:
//
//   1. Machine breakdown: a sewer's needle/bobbin/machine issue is
//      reported to the line leader, who calls the mechanic immediately
//      ("Patawag ka ng mekaniko agad-agad. Kasi wala sa timing yun.")
//   2. Cutting damage: a mover/cutter's mistake is reported on the spot;
//      the line leader confirms it was unintentional before the piece
//      is re-cut to catch up ("nagupit ng mananahi, hindi sinasadya —
//      on the spot i-rereport... i-admit ng line leader").
//
// Both are staff-level operational incidents — VFRB only has 3 real
// roles (customer/staff/manager), so "line leader" / "mover" / "mechanic"
// are staff acting in different shop-floor capacities, not separate
// system roles. reported_by and acknowledged_by are both staff/manager
// user_id values.
//
// Deliberately NOT modeled: a separate "mechanic" or "line leader" role
// — that would violate the locked 3-role rule. Acknowledgment is done by
// any staff/manager account, same as the rest of the operational pages.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_incidents', function (Blueprint $table) {
            $table->id('incident_id');

            // Nullable: a machine breakdown may not be tied to one specific
            // order (e.g. a machine goes down between jobs); cutting damage
            // almost always is.
            $table->unsignedBigInteger('order_id')->nullable();
            $table->foreign('order_id')->references('order_id')->on('orders')->nullOnDelete();

            $table->enum('incident_type', ['machine_breakdown', 'cutting_damage']);

            // Which of the 7 production stages this happened during —
            // nullable because machine breakdowns aren't always cleanly
            // tied to a single stage.
            $table->enum('stage', ['pattern','segregation','cutting','sewing','qc','pressing','packing'])
                  ->nullable();

            $table->unsignedBigInteger('reported_by');
            $table->foreign('reported_by')->references('user_id')->on('users');

            $table->text('description');

            // Cutting-damage specific: how many pieces were affected /
            // need re-cutting. Null for machine breakdowns.
            $table->unsignedInteger('qty_affected')->nullable();

            // reported -> acknowledged (line leader confirms not
            // intentional / mechanic dispatched) -> resolved (fixed /
            // re-cut completed)
            $table->enum('status', ['reported', 'acknowledged', 'resolved'])
                  ->default('reported');

            $table->unsignedBigInteger('acknowledged_by')->nullable();
            $table->foreign('acknowledged_by')->references('user_id')->on('users');
            $table->timestamp('acknowledged_at')->nullable();

            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_incidents');
    }
};
