<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H1200 — Jivo-паритет S5: разбейджить VK/TG-student-bot как отдельные каналы.
 *
 * Сейчас VK-бот ([`ProcessVkBotMessage`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Jobs/ProcessVkBotMessage.php))
 * и TG-student-bot ([`TelegramWebhookController`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/TelegramWebhookController.php))
 * пишут в `chat_messages` теми же полями, что и веб-виджет — ничем не отличимы
 * друг от друга в `UnifiedMessage::CHANNEL_WEB` (см. support-subsystem-map.md
 * gap #6). `source` — nullable, default NULL == веб-виджет (обратная
 * совместимость со старыми строками, ничего не бэкафиллим).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->string('source', 20)->nullable()->after('ai_state');
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
