<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Куда дублировать входящие апдейты @zapisi_ORSbot (n8n-сценарий «ловим названия»).
 *
 * У бота может быть ровно ОДИН вебхук. Пока его держал n8n, наш прод не видел
 * сообщений (пустой corpus с 22.07); когда вебхук забрали мы — перестал работать
 * сценарий записи названий в Google Sheets. Развязка: вебхук у нас, а апдейты
 * дублируются в n8n тем же ForwardUpdateToN8n, что уже работает у ботов лендингов.
 *
 * Адрес указывается локальный (n8n стоит в той же сети, 192.168.200.91): так
 * форвард не выходит в интернет и не упирается в блокировки, из-за которых
 * Telegram и не мог достучаться до n8n напрямую.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_settings', function (Blueprint $table) {
            $table->string('zapisi_n8n_forward_url')->nullable()->after('zapisi_reminder_template');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_settings', function (Blueprint $table) {
            $table->dropColumn('zapisi_n8n_forward_url');
        });
    }
};
