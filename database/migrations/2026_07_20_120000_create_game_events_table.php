<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Анонимная воронка бесплатных тренажёров /exercises (H1360).
 *
 * Отдельная таблица, а НЕ activity_events: тот рельс auth-only
 * (activity_events.user_id NOT NULL, CabinetTelemetry::emit(User $user,...)),
 * а здесь посетители анонимны. По контракту приватности R20
 * (first-party, без сторонних трекеров — см. CabinetTelemetry) таблица
 * СОЗНАТЕЛЬНО не хранит ни IP, ни user-agent: единственный идентификатор —
 * короткий случайный anon_id, минченный на клиенте, без PII. Это держит
 * таблицу вне периметра 152-ФЗ (персональных данных нет).
 *
 * Append-only: только created_at, updated_at нет (как ActivityEvent).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            // Короткая случайная строка с клиента (localStorage), не PII.
            $table->string('anon_id', 32)->nullable();
            // Семейство тренажёра (ligatures/roots/sort/match/cloze/index).
            $table->string('drill', 40);
            // Уровень внутри семейства (top-50/verb-roots/genders/...), может быть null.
            $table->string('band', 40)->nullable();
            // start | complete | gate_shown | gate_cta_click.
            $table->string('event', 20);
            // Флаг залогиненности берётся на сервере из web-сессии, не с клиента.
            $table->boolean('authenticated')->default(false);
            $table->dateTime('created_at')->useCurrent();

            $table->index(['event', 'created_at']);
            $table->index(['drill', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_events');
    }
};
