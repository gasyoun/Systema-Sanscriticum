<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_settings', function (Blueprint $table) {
            // Показывать ли студентам в кабинете блоки привязки ботов (TG/VK).
            // Выключено по умолчанию — включается из админки.
            $table->boolean('student_bots_enabled')->default(false)->after('student_maintenance_secret');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_settings', function (Blueprint $table) {
            $table->dropColumn('student_bots_enabled');
        });
    }
};
