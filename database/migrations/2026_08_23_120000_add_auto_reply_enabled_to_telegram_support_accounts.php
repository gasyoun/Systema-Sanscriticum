<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H3380: пер-аккаунтный выключатель автоответов (SupportDmAutoReply).
 * Глобальные флаги остаются в features.php; эта колонка разрешает
 * автоответ ТОЛЬКО на диалогах конкретной сессии (для пробы — аккаунт
 * rusamskrtam), основной support-аккаунт не меняет поведение, пока у него
 * auto_reply_enabled=false.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_support_accounts', function (Blueprint $table): void {
            $table->boolean('auto_reply_enabled')->default(false)->after('is_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_support_accounts', function (Blueprint $table): void {
            $table->dropColumn('auto_reply_enabled');
        });
    }
};
