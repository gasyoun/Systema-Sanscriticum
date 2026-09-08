<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H4429 (рулинг MG 08-09-2026: «live после недели тени, без переспрашивания»):
 * авто-рубильник живого режима LLM-ветки саппорта. Команда
 * support:llm-live-enable пишет штамп включения сюда (сейчас это код-рантайм
 * значение, читаемое SupportDailyDigest::llmLive()); поддержка-ветка включает
 * живой режим, не требуя правки .env на проде.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_settings', function (Blueprint $table) {
            $table->timestamp('support_llm_live_enabled_at')->nullable()->after('support_ai_daily_cap');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_settings', function (Blueprint $table) {
            $table->dropColumn('support_llm_live_enabled_at');
        });
    }
};
