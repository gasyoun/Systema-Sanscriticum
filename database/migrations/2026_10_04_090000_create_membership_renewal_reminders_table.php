<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H5823 — журнал последовательности напоминаний о продлении членства.
 *
 * Одна строка на (период, стадия): единственный дедуп отправки. Наличие
 * строки = стадия УЖЕ ушла (хотя бы один канал доставлен), отсутствие =
 * стадия ещё предстоит. Append-only, удалять строки руками запрещено —
 * иначе дубль уйдёт студенту на следующем проходе демона.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_renewal_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_membership_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Стадия последовательности: d7 / d3 / d0 / grace1 (config
            // membership.renewal_stages). Уникальность пары — дедуп.
            $table->string('stage', 32);
            // ends_at периода НА МОМЕНТ отправки — аудит-след: если период
            // потом продлили и ends_at уехал, строка хранит, о какой дате
            // напоминали.
            $table->timestamp('period_ends_at');
            // Какие каналы реально ушли: tg/vk/email через «+».
            $table->string('channels', 64)->default('');
            $table->timestamp('sent_at');

            $table->unique(['club_membership_id', 'stage']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_renewal_reminders');
    }
};
