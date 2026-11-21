<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_student_questions', function (Blueprint $table): void {
            $table->id();
            $table->string('telegram_chat_id', 32);
            $table->unsignedBigInteger('telegram_message_id');
            $table->foreignId('group_id')->nullable()->constrained('groups')->cascadeOnDelete();
            $table->string('author_telegram_id', 32)->nullable();
            $table->string('author_name')->nullable();
            $table->string('author_username')->nullable();
            // question — reply-вопрос на пост бота без опознанного типа;
            // proposal — студенческое предложение переноса (никогда не применяется).
            $table->string('kind', 16)->default('question');
            $table->string('post_kind', 16)->nullable();
            $table->text('question_text');
            $table->unsignedBigInteger('reply_to_message_id')->nullable();
            $table->text('replied_post_excerpt')->nullable();
            $table->string('status', 16)->default('new');
            $table->text('curator_draft')->nullable();
            $table->string('curator_draft_model')->nullable();
            $table->string('curator_draft_state', 16)->default('pending');
            $table->unsignedBigInteger('ack_message_id')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['telegram_chat_id', 'telegram_message_id'], 'tg_student_question_source_unique');
            $table->index(['status', 'created_at'], 'tg_student_question_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_student_questions');
    }
};
