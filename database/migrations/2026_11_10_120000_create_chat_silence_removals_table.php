<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Журнал «молчит в учебном чате + урок закрыт» → предложение админам →
 * исключение Почтальоном по нажатию (см. ChatSilenceRemovalService).
 * Одна строка = одно предложение по паре (студент, чат) и его исход.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_silence_removals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('group_id')->nullable()->constrained('groups')->nullOnDelete();
            $table->string('telegram_chat_id', 32);
            $table->string('telegram_user_id', 32);
            $table->dateTime('last_message_at')->nullable();
            $table->foreignId('lesson_id')->nullable()->constrained('lessons')->nullOnDelete();
            $table->unsignedInteger('block_number')->nullable();
            $table->string('status', 16)->default('proposed');
            $table->dateTime('proposed_at');
            $table->dateTime('next_offer_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->string('note', 500)->nullable();
            $table->dateTime('unbanned_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status'], 'chat_silence_user_status_index');
            $table->index(['telegram_chat_id', 'telegram_user_id', 'status'], 'chat_silence_chat_member_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_silence_removals');
    }
};
