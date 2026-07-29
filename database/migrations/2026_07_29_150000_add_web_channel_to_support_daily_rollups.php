<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H1837 — паритет измерения дефлекции: веб-чат в дневном rollup'е.
 *
 * `support_daily_rollups` до сих пор описывал ровно один канал — импортированный
 * TG-support-аккаунт (`telegram_support_chat_id` NOT NULL). Всё, что считает
 * дефлекцию поверх этой таблицы (`support:topic-ranking`, `content:detect-gaps`),
 * поэтому измеряло только Telegram: тема, приходящая в основном через веб-виджет
 * (а также VK/TG-student-bot — те пишут в те же `chat_messages`, H1200), в рейтинге
 * «что автоматизировать первым» выглядела маленькой. Это смещение измерения, а не
 * пробел в UI.
 *
 * Строка rollup'а получает `channel` (дефолт `telegram` — старые строки ровно те
 * же) и nullable `support_conversation_id`; `telegram_support_chat_id` становится
 * nullable, потому что у веб-треда нет TG-чата. Ключ уникальности на веб-стороне
 * свой: (support_conversation_id, conversation_date). Прецедент nullable-FK через
 * change() — add_guest_identity_to_support_chat (H536).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_daily_rollups', function (Blueprint $table) {
            $table->string('channel', 16)->default('telegram')->after('id');
            $table->foreignId('support_conversation_id')
                ->nullable()
                ->after('telegram_support_chat_id')
                ->constrained()
                ->cascadeOnDelete();
        });

        Schema::table('support_daily_rollups', function (Blueprint $table) {
            $table->unsignedBigInteger('telegram_support_chat_id')->nullable()->change();
        });

        Schema::table('support_daily_rollups', function (Blueprint $table) {
            $table->unique(
                ['support_conversation_id', 'conversation_date'],
                'support_daily_rollup_conversation_date_unique'
            );
            $table->index(['channel', 'conversation_date'], 'support_daily_rollup_channel_date_index');
        });
    }

    public function down(): void
    {
        Schema::table('support_daily_rollups', function (Blueprint $table) {
            $table->dropIndex('support_daily_rollup_channel_date_index');
            $table->dropUnique('support_daily_rollup_conversation_date_unique');
        });

        // Веб-строки не переносимы в Telegram-only форму — снимаем их перед тем,
        // как вернуть NOT NULL, иначе откат упадёт на живой базе.
        Schema::table('support_daily_rollups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('support_conversation_id');
        });

        \Illuminate\Support\Facades\DB::table('support_daily_rollups')
            ->whereNull('telegram_support_chat_id')
            ->delete();

        Schema::table('support_daily_rollups', function (Blueprint $table) {
            $table->unsignedBigInteger('telegram_support_chat_id')->nullable(false)->change();
            $table->dropColumn('channel');
        });
    }
};
