<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Индекс под кросс-полосный дедуп поддержки: persistNormalizedMessage и
     * isOutgoingSupportMessage ищут строку по (telegram_chat_id,
     * telegram_message_id) на КАЖДОЕ сообщение обеих полос, а единственный
     * прежний уникальный индекс имеет префиксом аккаунт — для этих поиском
     * бесполезен, каждый запрос был полным сканом таблицы (27k строк,
     * 153 МБ payload'ов). Без индекса миграция слияния дублей на проде
     * шла ~73 минуты (10:02→11:15 UTC, 07-10-2026).
     */
    public function up(): void
    {
        Schema::table('telegram_support_messages', function (Blueprint $table) {
            $table->index(['telegram_chat_id', 'telegram_message_id'], 'tsm_chat_message_idx');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_support_messages', function (Blueprint $table) {
            $table->dropIndex('tsm_chat_message_idx');
        });
    }
};
