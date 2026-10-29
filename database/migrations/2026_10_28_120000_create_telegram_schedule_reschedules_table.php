<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_schedule_reschedules', function (Blueprint $table): void {
            $table->id();
            $table->string('telegram_chat_id', 32);
            $table->unsignedBigInteger('telegram_message_id');
            $table->foreignId('schedule_id')->constrained('schedules')->cascadeOnDelete();
            $table->foreignId('group_id')->constrained('groups')->cascadeOnDelete();
            $table->string('actor_telegram_id', 32);
            $table->dateTime('old_start');
            $table->dateTime('old_end')->nullable();
            $table->dateTime('new_start');
            $table->dateTime('new_end')->nullable();
            $table->unsignedBigInteger('receipt_message_id')->nullable();
            $table->string('status', 16)->default('applied');
            $table->dateTime('undo_expires_at');
            $table->timestamps();

            $table->unique(['telegram_chat_id', 'telegram_message_id'], 'tg_reschedule_source_unique');
            $table->index(['schedule_id', 'status'], 'tg_reschedule_schedule_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_schedule_reschedules');
    }
};
