<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Воронка анкет построчно (H5098): аудит 12-09-2026 показал, что в
 * survey_responses падают только финальные отправки — 4/24 завершений
 * (16.7%) недиагностируемы. Таблица хранит события одного пути респондента:
 *
 *   sent      — приглашение реально ушло (статус приглашения sent);
 *   opened    — страница /anketa/{slug} открыта (первый раз на сессию);
 *   started   — первый ввод/клик в форме (первый раз на сессию);
 *   page      — переход на страницу N многостраничной анкеты;
 *   completed — успешный POST, создан survey_responses (per-response).
 *
 * Приватность: session_key — случайный UUID из куки, БЕЗ персональных
 * данных; агрегаты для куратора считают только количества (см.
 * SurveyPageController@funnel), строки при этом позволяют сопоставить
 * приглашение и ответ через survey_invitation_id / survey_response_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('survey_events', function (Blueprint $table) {
            $table->id();
            $table->string('survey_slug', 64);
            $table->foreignId('survey_invitation_id')->nullable()->constrained('survey_invitations')->nullOnDelete();
            $table->foreignId('survey_response_id')->nullable()->constrained('survey_responses')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('session_key', 64)->nullable();
            $table->string('event', 16);
            $table->unsignedTinyInteger('page_index')->nullable();
            $table->timestamps();

            $table->index(['survey_slug', 'event']);
            $table->index(['survey_slug', 'session_key', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_events');
    }
};
