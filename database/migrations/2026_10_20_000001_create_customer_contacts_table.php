<?php

// Walk-in customer contacts recorded by Administrators; no login or user account.
// user_id is set once a customer with the same verified email exists.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customer_contacts')) {
            return;
        }

        Schema::create('customer_contacts', function (Blueprint $table) {
            $table->id('contact_id');
            $table->string('name', 100);
            $table->string('email', 100)->nullable()->unique(); // stored lowercase by the app
            $table->string('mobile', 20)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('organization_name', 100)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users', 'user_id')->nullOnDelete();
            $table->timestamp('linked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users', 'user_id')->nullOnDelete();
            $table->timestamps();

            $table->index('mobile');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_contacts');
    }
};
