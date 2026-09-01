<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Волна 2 списка ожидания (MG 01-09-2026): ручная привязка кандидата к сезону
 * набора для Telegram-поста. Формат «YYYY-<slug>»: autumn | january | spring |
 * summer. Год = год начала сезона (2027-spring → «ВЕСНА 2027»). Null = рендерер
 * сам выводит сезон из earliest_start_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_waitlist_items', function (Blueprint $table) {
            $table->string('season', 20)->nullable()->after('earliest_start_at');
            $table->index('season');
        });
    }

    public function down(): void
    {
        Schema::table('course_waitlist_items', function (Blueprint $table) {
            $table->dropIndex(['season']);
            $table->dropColumn('season');
        });
    }
};