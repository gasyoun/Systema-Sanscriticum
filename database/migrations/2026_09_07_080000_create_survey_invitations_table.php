<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Журнал приглашений в опросы (H4297): первая постоянная построчная запись
 * «адресат / дата / message_id / статус» для личных приглашений через бота.
 * До этого таблицы не было — историю прежних опросных приглашений машина
 * доказать не могла (exit-price уходил черновиками куратору вручную).
 *
 * UNIQUE (survey_slug, user_id) — один человек получает одно приглашение
 * на волну независимо от перезапусков команды и гонок воркеров. Строку
 * со статусом queued создаём ДО отправки (резерв идемпотентности).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('survey_invitations', function (Blueprint $table) {
            $table->id();
            $table->string('survey_slug', 64)->index();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('telegram_chat_id');
            $table->string('channel', 16)->default('telegram');
            $table->string('status', 16)->default('queued');
            $table->unsignedBigInteger('telegram_message_id')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['survey_slug', 'user_id']);
            $table->index(['survey_slug', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_invitations');
    }
};
