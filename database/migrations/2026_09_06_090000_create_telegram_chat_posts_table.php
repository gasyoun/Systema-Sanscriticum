<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // H4199: посты zapisi-бота, привязанные к строке расписания.
        // Заполняется SendZapisiBotMessageJob после успешной отправки
        // (message_id приходит из ответа Telegram) — по нему reply-команда
        // админа («Отмена занятия») на пост «Скоро занятие» матчится
        // обратно в Schedule через CancelClassCommandService.
        Schema::create('telegram_chat_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->nullable()->constrained()->nullOnDelete();
            $table->string('chat_id');
            $table->unsignedBigInteger('message_id');
            $table->string('kind')->default('generic');
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->unique(['chat_id', 'message_id']);
            $table->index(['schedule_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_chat_posts');
    }
};
