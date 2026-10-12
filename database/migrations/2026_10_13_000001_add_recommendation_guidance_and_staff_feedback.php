<?php
// Trainable AI material recommendations, without fine-tuning:
//   recommendation_guidance  -> manager-written rules injected into the Gemini prompt as grounding
//   material_recommendations -> staff accept/reject verdict per recommended line
// Purely additive: a new table and nullable columns. Never stores quantity, price or formula.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('recommendation_guidance')) {
            Schema::create('recommendation_guidance', function (Blueprint $t) {
                $t->id('guidance_id');
                $t->string('scope_category', 60)->nullable(); // e.g. medical, hospitality; null = any
                $t->string('scope_garment', 80)->nullable();  // e.g. Scrub Top; null = any
                $t->text('rule');
                $t->boolean('is_active')->default(true);
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->foreign('updated_by')->references('user_id')->on('users')->nullOnDelete();
                $t->timestamps();
                $t->index(['is_active', 'scope_category', 'scope_garment']);
            });
        }

        if (!Schema::hasColumn('material_recommendations', 'staff_feedback')) {
            Schema::table('material_recommendations', function (Blueprint $t) {
                $t->string('staff_feedback', 10)->nullable(); // 'accept' | 'reject'
                $t->string('staff_feedback_note', 280)->nullable();
                $t->unsignedBigInteger('staff_feedback_by')->nullable();
                $t->timestamp('staff_feedback_at')->nullable();
                $t->foreign('staff_feedback_by')->references('user_id')->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('material_recommendations', 'staff_feedback')) {
            Schema::table('material_recommendations', function (Blueprint $t) {
                $t->dropForeign(['staff_feedback_by']);
                $t->dropColumn(['staff_feedback', 'staff_feedback_note', 'staff_feedback_by', 'staff_feedback_at']);
            });
        }
        Schema::dropIfExists('recommendation_guidance');
    }
};
