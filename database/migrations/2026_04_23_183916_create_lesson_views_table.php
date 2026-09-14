<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_views', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('lesson_id')
                ->constrained()
                ->cascadeOnDelete();

            // Денормализовано для быстрой аналитики по курсу без JOIN на lessons
            $table->foreignId('course_id')
                ->constrained()
                ->cascadeOnDelete();

            // Первый раз когда студент открыл этот урок (не меняется)
            $table->dateTime('first_opened_at');
            $table->dateTime('last_opened_at');

            // Сколько раз открывал урок
            $table->unsignedInteger('open_count')->default(1);

            // Суммарно секунд, проведённых на странице урока (из heartbeat)
            $table->unsignedInteger('total_time_on_page')->default(0);

            // НЕ ЗАПОЛНЯЕТСЯ. Задумывался как денормализация пивота ради аналитики
            // без JOIN, но синхронизации так и не написали: пройденный урок пишет
            // только App\Models\User::completedLessons() (пивот lesson_user), а сюда
            // строка всегда создаётся с false и больше не трогается. Прод 01-09-2026:
            // 649 строк, из них с true — 0, при 166 пройденных уроках в пивоте.
            // ЗАВЕРШАЕМОСТЬ СЧИТАТЬ ПО lesson_user.is_completed. Метрика, севшая сюда,
            // покажет ровный 0 % по каждому курсу — число, неотличимое от честного нуля
            // (H3764, issue #2299; пин — ActivationCompletionMetricsServiceTest).
            $table->boolean('is_completed')->default(false);

            $table->timestamps();

            // Уникальный ключ — один урок = одна строка на юзера. По нему идёт upsert.
            $table->unique(['user_id', 'lesson_id']);

            // Индексы под типовые запросы
            $table->index(['course_id', 'last_opened_at']);  // топ просматриваемых в курсе
            $table->index(['user_id', 'last_opened_at']);    // последняя активность студента
            $table->index('last_opened_at');                  // для виджета "что смотрели сегодня"
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_views');
    }
};
