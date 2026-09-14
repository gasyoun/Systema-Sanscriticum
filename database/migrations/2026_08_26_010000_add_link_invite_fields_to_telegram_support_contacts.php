<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * H3542: self-service связывание Telegram-партнёра с LMS-юзером.
     * link_token_hash — capability-токен ссылки из приглашения (plaintext уходит
     * в DM, в БД только SHA-256, как magic_link_tokens); link_invited_at —
     * cooldown-отметка «приглашение уже отправляли», не чаще окна.
     */
    public function up(): void
    {
        Schema::table('telegram_support_contacts', function (Blueprint $table) {
            $table->string('link_token_hash')->nullable()->unique();
            $table->timestamp('link_token_expires_at')->nullable();
            $table->timestamp('link_invited_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('telegram_support_contacts', function (Blueprint $table) {
            $table->dropColumn(['link_token_hash', 'link_token_expires_at', 'link_invited_at']);
        });
    }
};
