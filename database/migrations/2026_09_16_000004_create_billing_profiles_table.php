<?php
// database/migrations/2026_09_16_000002_create_billing_profiles_table.php
//
// Saved invoice-recipient details, NOT a payment method vault — VFRB has no
// online payment gateway (real payment terms per interview: bank transfer,
// staff-confirmed, 80% DP + 20% on delivery for direct clients / as-per-
// delivery for OTG subcontract). A corporate/school client may want the
// official receipt made out to the org rather than the individual account
// holder — that's what this stores. Not wired into invoice generation yet;
// that's a separate later task once this exists.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_profiles', function (Blueprint $table) {
            $table->id('billing_id');
            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')->references('user_id')->on('users')->onDelete('cascade');
            $table->string('billing_name', 150);   // org or individual name on the receipt
            $table->string('tin', 20)->nullable();  // PH Tax Identification Number
            $table->string('billing_address', 255);
            $table->string('billing_email', 100)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_profiles');
    }
};
