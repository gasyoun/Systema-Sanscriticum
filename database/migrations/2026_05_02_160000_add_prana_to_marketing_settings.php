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
            $table->boolean('is_prana_active')->default(true)->after('wholesale_large_discount');

            // Курс конвертации: сколько праны = 1 ₽.
            $table->unsignedSmallInteger('prana_rate')->default(10);
            // Доля от итоговой цены, которую можно покрыть праной (в процентах).
            $table->unsignedTinyInteger('prana_max_share_percent')->default(30);

            // Награды за активности.
            $table->unsignedSmallInteger('prana_reward_lesson_complete')->default(10);
            $table->unsignedSmallInteger('prana_reward_course_complete')->default(500);
            $table->unsignedSmallInteger('prana_reward_open_lesson_view')->default(20);
            $table->unsignedSmallInteger('prana_reward_daily_login')->default(5);
            $table->unsignedSmallInteger('prana_reward_payment_success')->default(50);
        });

        // Переносим текущие значения из config/prana.php в существующую строку настроек,
        // чтобы поведение не сменилось после миграции.
        $defaults = [
            'is_prana_active' => true,
            'prana_rate' => (int) config('prana.rate', 10),
            'prana_max_share_percent' => (int) round((float) config('prana.max_share_of_price', 0.30) * 100),
            'prana_reward_lesson_complete' => (int) config('prana.rewards.lesson_complete', 10),
            'prana_reward_course_complete' => (int) config('prana.rewards.course_complete', 500),
            'prana_reward_open_lesson_view' => (int) config('prana.rewards.open_lesson_view', 20),
            'prana_reward_daily_login' => (int) config('prana.rewards.daily_login', 5),
            'prana_reward_payment_success' => (int) config('prana.rewards.payment_success', 50),
        ];

        DB::table('marketing_settings')->update($defaults);
    }

    public function down(): void
    {
        Schema::table('marketing_settings', function (Blueprint $table) {
            $table->dropColumn([
                'is_prana_active',
                'prana_rate',
                'prana_max_share_percent',
                'prana_reward_lesson_complete',
                'prana_reward_course_complete',
                'prana_reward_open_lesson_view',
                'prana_reward_daily_login',
                'prana_reward_payment_success',
            ]);
        });
    }
};
