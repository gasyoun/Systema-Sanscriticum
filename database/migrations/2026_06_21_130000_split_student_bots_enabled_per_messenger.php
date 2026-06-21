<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_settings', function (Blueprint $table) {
            $table->boolean('student_telegram_bot_enabled')->default(false)->after('student_bots_enabled');
            $table->boolean('student_vk_bot_enabled')->default(false)->after('student_telegram_bot_enabled');
        });

        // Переносим текущее единое значение в оба новых тумблера, чтобы поведение не изменилось.
        DB::table('marketing_settings')->update([
            'student_telegram_bot_enabled' => DB::raw('student_bots_enabled'),
            'student_vk_bot_enabled' => DB::raw('student_bots_enabled'),
        ]);

        Schema::table('marketing_settings', function (Blueprint $table) {
            $table->dropColumn('student_bots_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_settings', function (Blueprint $table) {
            $table->boolean('student_bots_enabled')->default(false)->after('student_maintenance_secret');
        });

        // Старый общий тумблер = включён, если включён хотя бы один из мессенджеров.
        DB::table('marketing_settings')->update([
            'student_bots_enabled' => DB::raw('(student_telegram_bot_enabled OR student_vk_bot_enabled)'),
        ]);

        Schema::table('marketing_settings', function (Blueprint $table) {
            $table->dropColumn(['student_telegram_bot_enabled', 'student_vk_bot_enabled']);
        });
    }
};
