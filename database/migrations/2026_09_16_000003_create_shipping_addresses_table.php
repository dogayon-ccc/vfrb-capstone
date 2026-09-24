<?php
// database/migrations/2026_09_16_000001_create_shipping_addresses_table.php
//
// Customer-owned address book. VFRB's own delivery coordination is manual
// (delivery_tracking, staff-confirmed per order per interview) — this table
// only saves a customer's own repeat delivery sites (useful for corporate/
// school/medical clients with multiple sites, e.g. OTG bulk, PNG export)
// so they don't retype it per order. Not wired into OrderWizard yet — that
// wiring is a separate, later task once this exists and is confirmed useful.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_addresses', function (Blueprint $table) {
            $table->id('shipping_id');
            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')->references('user_id')->on('users')->onDelete('cascade');
            $table->string('label', 50);              // e.g. "Main Office", "Warehouse"
            $table->string('recipient_name', 100);
            $table->string('contact_number', 20);
            $table->string('address_line', 255);
            $table->string('city', 100);
            $table->string('province', 100);
            $table->string('postal_code', 10)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_addresses');
    }
};
