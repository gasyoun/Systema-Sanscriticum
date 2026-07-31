<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Связка «сообщение бота в чате группы → урок» для #ДЗ (волна 2, звено C).
 * Когда преподаватель отвечает reply на пост «ДЗ открыто», webhook находит
 * lesson_id по (telegram_chat_id, telegram_message_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('lesson_telegram_hooks')) {
            return;
        }

        Schema::create('lesson_telegram_hooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();
            $table->foreignId('group_id')->nullable()->constrained('groups')->nullOnDelete();
            $table->string('telegram_chat_id', 64);
            $table->unsignedBigInteger('telegram_message_id');
            $table->string('purpose', 32)->default('open_invite');
            $table->timestamps();

            $table->unique(
                ['telegram_chat_id', 'telegram_message_id'],
                'lesson_tg_hooks_chat_msg_unique',
            );
            $table->index(['lesson_id', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_telegram_hooks');
    }
};
