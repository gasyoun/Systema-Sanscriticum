<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Разовое напоминание студенту на конкретную дату/время (куратор ставит
    // руками, например «через 12.07 попросить отзыв о курсе»). Отправляется
    // автоматически командой reminders:send-due — без ручного пинга куратором.
    public function up(): void
    {
        Schema::create('scheduled_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('message');
            $table->boolean('to_telegram')->default(true);
            $table->boolean('to_vk')->default(false);
            $table->boolean('to_email')->default(false);
            $table->timestamp('scheduled_for')->index();
            $table->timestamp('sent_at')->nullable()->index();
            $table->timestamps();

            $table->index(['scheduled_for', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_reminders');
    }
};
