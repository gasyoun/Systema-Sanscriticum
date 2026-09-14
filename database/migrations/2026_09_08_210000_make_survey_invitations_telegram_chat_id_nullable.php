<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Канал email в журнале приглашений (H4431): строки channel=email адресованы
 * письмом, а не ботом, поэтому telegram_chat_id для них пуст. Колонка станет
 * NULLable; telegram-строки продолжают хранить chat_id как раньше.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('survey_invitations', function (Blueprint $table) {
            $table->unsignedBigInteger('telegram_chat_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('survey_invitations', function (Blueprint $table) {
            $table->unsignedBigInteger('telegram_chat_id')->nullable(false)->change();
        });
    }
};
