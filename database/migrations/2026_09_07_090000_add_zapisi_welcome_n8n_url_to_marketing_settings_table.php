<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H4314: отдельный n8n webhook для приветственной карточки @zapisi_ORSbot.
 *
 * ПроцессTelegramZapisiUpdate роутит my_chat_member-апдейты (бот добавлен в
 * чат) сюда, а message/channel_post — в zapisi_n8n_forward_url как раньше.
 * Пусто = приветствие выключено (n8n-воркфлоу не активен).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_settings', function (Blueprint $table) {
            $table->string('zapisi_welcome_n8n_url')->nullable()->after('zapisi_n8n_forward_url');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_settings', function (Blueprint $table) {
            $table->dropColumn('zapisi_welcome_n8n_url');
        });
    }
};
