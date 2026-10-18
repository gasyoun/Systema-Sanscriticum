<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** «✅ Я иду» / «Не смогу» под карточкой открытого занятия; zoom_sent_at — ссылка ушла. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('open_lesson_rsvps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('schedule_id')->constrained()->cascadeOnDelete();
            $table->string('chat_id', 32);
            $table->timestamp('going_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->timestamp('zoom_sent_at')->nullable();
            $table->timestamps();

            $table->unique(['schedule_id', 'chat_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('open_lesson_rsvps');
    }
};
