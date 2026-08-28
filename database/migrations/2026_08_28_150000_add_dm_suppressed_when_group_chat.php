<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Дубль-гвардия каналов напоминания о занятии (диагноз 28-08-2026): студенты групп
 * с Telegram-чатом получали ЛС «Скоро занятие» (classes:remind-upcoming, T-60) и почти
 * тот же пост @zapisi_ORSbot в чат группы (zapisi:remind-classes, тоже T-60) с разницей
 * в 1–2 минуты. Рубильник глушит ЛС-волну для занятий, чья группа уже покрыта чатом.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_settings', function (Blueprint $table): void {
            $table->boolean('dm_suppressed_when_group_chat')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('marketing_settings', function (Blueprint $table): void {
            $table->dropColumn('dm_suppressed_when_group_chat');
        });
    }
};
