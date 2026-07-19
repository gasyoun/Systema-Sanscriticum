<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Telegram Track C (H164, Uprava/docs/DECISIONS_telegram_harvester.md D8/D9/D11):
 * the zapisi_ORSbot's own token/secret/chat id, same encrypted-at-rest pattern
 * as the lead-magnet bot fields (tg_bot_token/tg_webhook_secret) on this model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_settings', function (Blueprint $table) {
            $table->string('zapisi_bot_username')->nullable();
            $table->text('zapisi_bot_token')->nullable();
            $table->text('zapisi_webhook_secret')->nullable();
            $table->string('zapisi_chat_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('marketing_settings', function (Blueprint $table) {
            $table->dropColumn(['zapisi_bot_username', 'zapisi_bot_token', 'zapisi_webhook_secret', 'zapisi_chat_id']);
        });
    }
};
