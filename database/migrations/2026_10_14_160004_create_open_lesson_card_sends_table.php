<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Журнал карточек открытых занятий и одновременно клейм отправки: строка
 * вставляется ДО вызова Telegram, unique (schedule, chat, stage) не даёт
 * второму прогону или ретраю отправить ту же стадию повторно.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('open_lesson_card_sends', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('schedule_id')->constrained()->cascadeOnDelete();
            $table->string('chat_id', 32);
            $table->string('stage', 16);
            $table->unsignedBigInteger('message_id')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['schedule_id', 'chat_id', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('open_lesson_card_sends');
    }
};
