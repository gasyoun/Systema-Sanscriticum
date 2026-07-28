<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Автор техвопроса из Telegram-чата, когда он НЕ привязан к аккаунту студента.
 *
 * До этого такие сообщения отбрасывались (TechnicalIssueRouter требовал
 * users-запись), хотя ни приём, ни ответ её не используют — юзербот работает по
 * telegram_chat_id. Теперь тред заводится и без user_id, но одного chat_id мало:
 * в учебной группе пишут разные люди, и без автора их вопросы слиплись бы в один
 * тред. Пара «чат + автор» и есть ключ такого треда.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_conversations', function (Blueprint $table) {
            $table->bigInteger('source_telegram_user_id')->nullable()->after('source_telegram_chat_id');

            // Поиск открытого треда идёт ровно по этой паре (см.
            // SupportConversationManager::openForTelegramChat).
            $table->index(['source_telegram_chat_id', 'source_telegram_user_id'], 'support_conv_tg_chat_user_idx');
        });
    }

    public function down(): void
    {
        Schema::table('support_conversations', function (Blueprint $table) {
            $table->dropIndex('support_conv_tg_chat_user_idx');
            $table->dropColumn('source_telegram_user_id');
        });
    }
};
