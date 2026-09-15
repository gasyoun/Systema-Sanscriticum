<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Опросы @zapisi_ORSbot в чатах групп (sendPoll, НЕ анонимные) —
        // куратор пишет вопрос и варианты в админке, голоса приходят апдейтом
        // poll_answer. poll_answer не несёт чата — матч только по tg_poll_id
        // из ответа sendPoll, отсюда unique.
        Schema::create('telegram_polls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->nullable()->constrained()->nullOnDelete();
            $table->string('chat_id');
            $table->string('question', 300);
            $table->json('options');
            $table->boolean('allows_multiple')->default(false);
            $table->string('status')->default('pending');
            $table->string('tg_poll_id')->nullable()->unique();
            $table->unsignedBigInteger('message_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['group_id', 'created_at']);
        });

        // Один голос (последний) на участника; пустой option_ids — голос отозван.
        // user_id — студент кабинета, если его Telegram привязан (users.telegram_id
        // или social_accounts), иначе остаются tg_username / tg_name.
        Schema::create('telegram_poll_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_poll_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('telegram_user_id');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('tg_username')->nullable();
            $table->string('tg_name')->nullable();
            $table->json('option_ids');
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();

            $table->unique(['telegram_poll_id', 'telegram_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_poll_answers');
        Schema::dropIfExists('telegram_polls');
    }
};
