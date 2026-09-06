<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H4199: whitelist telegram user_id для reply-команды «Отмена занятия».
 *
 * Через запятую: telegram user_id админов (MG + преподаватели), которые могут
 * ответить «Отмена занятия» на пост-напоминание @zapisi_ORSbot в чате группы,
 * чтобы занятие отменилось (каскад +7 дней, ScheduleMover::cancelAndShiftWeek)
 * и бот опубликовал анонс. Пусто = команда выключена.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_settings', function (Blueprint $table) {
            $table->string('zapisi_cancel_admin_ids')->nullable()->after('zapisi_reminder_template');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_settings', function (Blueprint $table) {
            $table->dropColumn('zapisi_cancel_admin_ids');
        });
    }
};
