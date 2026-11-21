<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * H8527: мульти-перенос («перенести 24.12 и 31.12 на 26.12 и 28.12») создаёт
     * несколько TelegramScheduleReschedule из одного сообщения-источника, поэтому
     * уникальность (chat, message) заменяется на (chat, message, schedule).
     */
    public function up(): void
    {
        Schema::table('telegram_schedule_reschedules', function (Blueprint $table): void {
            $table->dropUnique('tg_reschedule_source_unique');
            $table->unique(['telegram_chat_id', 'telegram_message_id', 'schedule_id'], 'tg_reschedule_source_schedule_unique');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_schedule_reschedules', function (Blueprint $table): void {
            $table->dropUnique('tg_reschedule_source_schedule_unique');
            $table->unique(['telegram_chat_id', 'telegram_message_id'], 'tg_reschedule_source_unique');
        });
    }
};
