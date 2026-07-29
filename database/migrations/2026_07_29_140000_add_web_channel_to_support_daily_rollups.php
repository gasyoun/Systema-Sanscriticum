<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * H1837 (S10) — дневные rollup'ы поддержки становятся ДВУХКАНАЛЬНЫМИ.
 *
 * До этой миграции `support_daily_rollups` мог описывать только TG-сторону:
 * обязательный FK на `telegram_support_chats`. Вся измерительная надстройка
 * (deflection S2/S7, поиск контент-дыр CAI3, «Наблюдаемость») из-за этого не
 * видела веб-виджет, VK-бота и TG-student-бота вовсе — известная асимметрия из
 * docs/support-subsystem-map.md («Actually open», строки 101/104).
 *
 * Решение — ОДНА таблица с колонкой-дискриминатором `channel`, а не параллельная:
 * сводный отчёт по обоим каналам тогда получается без ручной сверки, а KPI и
 * топики считаются одним кодом. Это НЕ противоречит запрету «не сливайте
 * таблицы» из support-subsystem-map: запрет про ХРАНИЛИЩА СООБЩЕНИЙ
 * (`chat_messages` vs `telegram_support_messages`) — они остаются раздельными,
 * а rollup всегда был агрегатом, а не сообщением.
 *
 * Ключ субъекта — ровно одна из трёх колонок:
 *   - `telegram_support_chat_id` — TG-support (channel=telegram), как раньше;
 *   - `support_conversation_id`  — веб-тред (в т.ч. гость: у гостя нет `users`-строки,
 *     реконсиляция идёт по треду — см. миграцию guest_identity);
 *   - `web_user_id` — сообщения `chat_messages` БЕЗ треда: VK-бот
 *     (ProcessVkBotMessage), TG-student-бот (TelegramWebhookController), ручные
 *     ответы из UserResource\Pages\Dialogs и исторические строки до H536.
 *     Без этого запасного ключа «100 % входящих обоих каналов» было бы неправдой.
 *
 * Существующие строки backfill'ятся в channel='telegram' — их семантика не меняется.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_daily_rollups', function (Blueprint $table) {
            $table->string('channel', 16)->default('telegram')->after('id');
            $table->foreignId('support_conversation_id')->nullable()->after('telegram_support_chat_id')
                ->constrained('support_conversations')->nullOnDelete();
            $table->foreignId('web_user_id')->nullable()->after('support_conversation_id')
                ->constrained('users')->nullOnDelete();
            // KPI «висит без ответа дольше порога» (config support.rollup.unresolved_after_hours).
            // Снимок на момент агрегации, пересчитывается при повторном прогоне дня.
            $table->boolean('unresolved_after_hours')->default(false)->after('is_unanswered');
        });

        // Строки, созданные до H1837, все до одной телеграмные.
        DB::table('support_daily_rollups')->update(['channel' => 'telegram']);

        Schema::table('support_daily_rollups', function (Blueprint $table) {
            $table->unsignedBigInteger('telegram_support_chat_id')->nullable()->change();
        });

        Schema::table('support_daily_rollups', function (Blueprint $table) {
            $table->unique(
                ['support_conversation_id', 'channel', 'conversation_date'],
                'support_daily_rollup_thread_date_unique'
            );
            $table->unique(
                ['web_user_id', 'channel', 'conversation_date'],
                'support_daily_rollup_web_user_date_unique'
            );
            $table->index(['conversation_date', 'channel'], 'support_daily_rollup_date_channel_index');
            $table->index(['conversation_date', 'unresolved_after_hours'], 'support_daily_rollup_date_unresolved_index');
        });
    }

    public function down(): void
    {
        Schema::table('support_daily_rollups', function (Blueprint $table) {
            $table->dropIndex('support_daily_rollup_date_unresolved_index');
            $table->dropIndex('support_daily_rollup_date_channel_index');
            $table->dropUnique('support_daily_rollup_web_user_date_unique');
            $table->dropUnique('support_daily_rollup_thread_date_unique');
        });

        // Веб-строки нельзя выразить без nullable FK — снимаем их прежде, чем
        // возвращать колонке NOT NULL.
        DB::table('support_daily_rollups')->whereNull('telegram_support_chat_id')->delete();

        Schema::table('support_daily_rollups', function (Blueprint $table) {
            $table->unsignedBigInteger('telegram_support_chat_id')->nullable(false)->change();
        });

        Schema::table('support_daily_rollups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('web_user_id');
            $table->dropConstrainedForeignId('support_conversation_id');
            $table->dropColumn(['channel', 'unresolved_after_hours']);
        });
    }
};
