<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Авто-напоминания о занятиях через @zapisi_ORSbot прямо из расписания
 * (Schedule → group.telegram_chat_id), заменяют ручную таблицу zapisi_class_schedules.
 * - schedules.zapisi_reminded_at — дедуп (одно напоминание на событие; сбрасывается
 *   при переносе start, как reminded_at/group_link_posted_at).
 * - marketing_settings.zapisi_reminder_* — окно и шаблон текста.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedules', function (Blueprint $table): void {
            $table->timestamp('zapisi_reminded_at')->nullable()->after('group_link_posted_at');
        });

        Schema::table('marketing_settings', function (Blueprint $table): void {
            $table->unsignedInteger('zapisi_reminder_lead_minutes')->default(60);
            $table->text('zapisi_reminder_template')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('schedules', function (Blueprint $table): void {
            $table->dropColumn('zapisi_reminded_at');
        });

        Schema::table('marketing_settings', function (Blueprint $table): void {
            $table->dropColumn(['zapisi_reminder_lead_minutes', 'zapisi_reminder_template']);
        });
    }
};
