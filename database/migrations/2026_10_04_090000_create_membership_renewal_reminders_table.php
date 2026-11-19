<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H5823 — журнал последовательности напоминаний о продлении.
 *
 * Одна строка на (поверхность, объект, стадия, дата конца периода):
 * единственный дедуп отправки. Наличие строки = стадия УЖЕ ушла за ЭТОТ
 * период, отсутствие = стадия ещё предстоит. Append-only, удалять строки
 * руками запрещено — иначе дубль уйдёт студенту на следующем проходе.
 *
 * Поверхности (surface):
 *  - club_period     — club_memberships.id (строка = ОДИН оплаченный период);
 *  - access_window   — course_access_windows.id (строка upsert-ится по паре
 *    (user, course), ends_at уезжает вперёд при продлении) — поэтому дата
 *    конца периода входит в ключ дедупа: новый срок = новая последовательность.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_renewal_reminders', function (Blueprint $table) {
            $table->id();
            $table->string('surface', 24); // club_period | access_window
            $table->unsignedBigInteger('subject_id'); // id строки поверхности
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Стадия последовательности: d7 / d3 / d0 / grace1 (config
            // membership.renewal_stages). Входит в ключ дедупа.
            $table->string('stage', 32);
            // ends_at периода НА МОМЕНТ отправки — аудит-след И часть ключа
            // дедупа (upsert-поверхности: продлили — ends_at уехал — напоминаем заново).
            $table->timestamp('period_ends_at');
            // Какие каналы реально ушли: tg/vk/email через «+».
            $table->string('channels', 64)->default('');
            $table->timestamp('sent_at');

            $table->unique(['surface', 'subject_id', 'stage', 'period_ends_at'], 'mrr_dedup_unique');
            $table->index('user_id');
            $table->index(['surface', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_renewal_reminders');
    }
};
