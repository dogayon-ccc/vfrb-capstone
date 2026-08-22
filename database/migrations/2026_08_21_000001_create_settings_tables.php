<?php
// database/migrations/2026_08_21_000001_create_settings_tables.php
//
// System Settings backend (Aug 21 2026 session).
//
// company_settings — single-row company config (branding + contact).
//   Enforced single row via fixed id=1 (checked in controller, not a DB
//   trigger — matches this repo's style of app-level guards over DB
//   constraints elsewhere). logo_path stores the disk path/key returned
//   by Storage::put(), NOT a public URL — the controller builds the URL
//   at read time so swapping disks (local -> s3) later doesn't orphan
//   old rows.
//
// notification_preferences — one row per user, toggles for email/SMS.
//   sms_enabled exists in the schema now for future use but has NO
//   real provider behind it yet (confirmed with Dave, Aug 21 2026 —
//   no SMS gateway configured anywhere in this codebase). Frontend
//   must label it "Not yet active". Do not wire this to anything that
//   implies it sends real texts.
//   email_enabled: also flagged in the UI — Mailtrap is currently a
//   SANDBOX inbox only (test captures, not real delivery). Until a
//   production mail provider (Postmark/SES/Resend — all three already
//   have config() entries in services.php but none confirmed live) is
//   configured, this toggle also does not cause real emails to be sent.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('company_settings')) {
            Schema::create('company_settings', function (Blueprint $table) {
                $table->unsignedTinyInteger('id')->primary(); // always 1 — enforced in controller
                $table->string('company_name', 150)->default('VFRB Enterprise');
                $table->string('logo_path', 255)->nullable(); // disk path, not URL — see note above
                $table->string('address', 255)->nullable();
                $table->string('contact_number', 20)->nullable();
                $table->string('contact_email', 100)->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->foreign('updated_by')->references('user_id')->on('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('notification_preferences')) {
            Schema::create('notification_preferences', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->primary();
                $table->foreign('user_id')->references('user_id')->on('users')->cascadeOnDelete();
                $table->boolean('email_enabled')->default(true);
                // NOT wired to any provider yet — schema-only, see note above.
                $table->boolean('sms_enabled')->default(false);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('company_settings');
    }
};
