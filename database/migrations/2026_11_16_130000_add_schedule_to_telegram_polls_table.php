<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Автоопрос о присутствии перед занятием (classes:post-attendance-poll).
 * Опрос знает своё занятие: kind='attendance', schedule_id и schedule_start —
 * время начала на момент отправки. По (group_id, schedule_start) команда не
 * шлёт второй опрос на тот же слот, а после переноса занятия шлёт новый.
 * Ручные опросы куратора остаются kind='manual' без занятия.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_polls', function (Blueprint $table) {
            $table->string('kind', 32)->default('manual')->after('chat_id');
            $table->foreignId('schedule_id')->nullable()->after('kind')->constrained()->nullOnDelete();
            $table->dateTime('schedule_start')->nullable()->after('schedule_id');

            $table->index(['kind', 'group_id', 'schedule_start'], 'telegram_polls_kind_group_start_index');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_polls', function (Blueprint $table) {
            $table->dropIndex('telegram_polls_kind_group_start_index');
            $table->dropConstrainedForeignId('schedule_id');
            $table->dropColumn(['kind', 'schedule_start']);
        });
    }
};
