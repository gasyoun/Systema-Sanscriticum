<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H3393: получатели подсказок «сложный вопрос» на уровне аккаунта.
 * null/пусто — прежнее поведение (ADMIN_TELEGRAM_ID). Для rusamskrtam сюда
 * идёт chat_id Насти, чтобы подсказки видела отвечающая, а не только админ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_support_accounts', function (Blueprint $table): void {
            $table->json('hint_recipients')->nullable()->after('auto_reply_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_support_accounts', function (Blueprint $table): void {
            $table->dropColumn('hint_recipients');
        });
    }
};
