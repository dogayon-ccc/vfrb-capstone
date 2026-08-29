<?php
// database/migrations/2026_08_27_000002_create_feedback_table.php
//
// Minimal feedback system, scoped deliberately small per Dave's own call
// (Aug 27 2026): no upvoting, no public roadmap board — just a way for any
// authenticated user (customer or staff/manager) to send VFRB the team a
// short note, and for a manager to see the list. If a public roadmap-style
// board is wanted later, that's a distinct, larger feature — not this one.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback', function (Blueprint $table) {
            $table->id('feedback_id');
            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')->references('user_id')->on('users');
            $table->enum('category', ['bug', 'suggestion', 'other'])->default('other');
            $table->text('message');
            // Manager-only triage — not a public status a customer sees;
            // that would need a very different, larger feature.
            $table->enum('status', ['new', 'reviewed', 'archived'])->default('new');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback');
    }
};
