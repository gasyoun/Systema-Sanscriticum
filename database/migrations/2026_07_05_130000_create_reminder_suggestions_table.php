<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Обнаруженная в переписке (веб-чат/TG) просьба «напомните мне» — куратор
    // подтверждает/правит/отклоняет, только после этого рождается ScheduledReminder.
    // Детектор сам ничего не отправляет — это только очередь предложений.
    public function up(): void
    {
        Schema::create('reminder_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('source_type'); // chat_message | telegram_support_message
            $table->unsignedBigInteger('source_id');
            $table->text('detected_text')->nullable();
            $table->timestamp('suggested_date')->nullable();
            $table->float('confidence')->default(0);
            $table->string('status')->default('pending'); // pending|approved|dismissed|expired
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('scheduled_reminder_id')->nullable()->constrained('scheduled_reminders')->nullOnDelete();
            $table->timestamps();

            $table->unique(['source_type', 'source_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_suggestions');
    }
};
