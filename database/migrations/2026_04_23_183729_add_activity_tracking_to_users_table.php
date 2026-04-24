<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Когда последний раз логинился (обновляется при событии Login)
            $table->timestamp('last_login_at')->nullable()->after('remember_token');

            // Когда последний раз была активность — клик, открытие страницы, heartbeat
            // Именно это поле отвечает на вопрос "кто онлайн"
            $table->timestamp('last_activity_at')->nullable()->after('last_login_at');

            // IP последнего логина (для безопасности и аналитики гео)
            $table->string('last_login_ip', 45)->nullable()->after('last_activity_at');

            // Счётчики — денормализованы для быстрой фильтрации в Filament без JOIN-ов
            $table->unsignedInteger('login_count')->default(0)->after('last_login_ip');

            // Суммарное время в кабинете в секундах (пересчитывается из user_sessions)
            $table->unsignedInteger('total_time_spent')->default(0)->after('login_count');

            // Уникальных уроков, которые студент хоть раз открыл
            $table->unsignedInteger('total_lessons_opened')->default(0)->after('total_time_spent');

            // Индекс для быстрой фильтрации "неактивные N дней"
            $table->index('last_activity_at');
            $table->index('last_login_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['last_activity_at']);
            $table->dropIndex(['last_login_at']);
            $table->dropColumn([
                'last_login_at',
                'last_activity_at',
                'last_login_ip',
                'login_count',
                'total_time_spent',
                'total_lessons_opened',
            ]);
        });
    }
};