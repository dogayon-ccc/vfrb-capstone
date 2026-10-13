<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Per-user appearance on the existing one-row-per-user preferences table. Both nullable: null = app default (light, VFRB teal).
//   theme_mode   : light | dark | system
//   theme_accent : preset id from src/utils/accentColor.js (teal, navy, royal, bottle, maroon, charcoal, red)
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_preferences', function (Blueprint $table) {
            if (!Schema::hasColumn('notification_preferences', 'theme_mode')) {
                $table->string('theme_mode', 10)->nullable()->after('sms_enabled');
            }
            if (!Schema::hasColumn('notification_preferences', 'theme_accent')) {
                $table->string('theme_accent', 20)->nullable()->after('theme_mode');
            }
        });
    }

    public function down(): void
    {
        Schema::table('notification_preferences', function (Blueprint $table) {
            foreach (['theme_accent', 'theme_mode'] as $col) {
                if (Schema::hasColumn('notification_preferences', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
