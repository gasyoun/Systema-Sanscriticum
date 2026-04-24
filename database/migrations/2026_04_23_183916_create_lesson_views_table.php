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

            // Синхронизируется с pivot completed_lessons — для быстрой аналитики без JOIN
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