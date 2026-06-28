<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_settings', function (Blueprint $table) {
            // Авто-уведомление студентам, пропустившим занятие (опт-ин, по умолчанию
            // выключено — чтобы не спамить «вы пропустили» без явного включения).
            if (! Schema::hasColumn('marketing_settings', 'absent_notify_enabled')) {
                $table->boolean('absent_notify_enabled')->default(false)->after('class_reminder_lead_minutes');
            }

            // Через сколько минут после конца занятия слать уведомление об отсутствии.
            if (! Schema::hasColumn('marketing_settings', 'absent_notify_delay_minutes')) {
                $table->unsignedInteger('absent_notify_delay_minutes')->default(30)->after('absent_notify_enabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('marketing_settings', function (Blueprint $table) {
            foreach (['absent_notify_enabled', 'absent_notify_delay_minutes'] as $column) {
                if (Schema::hasColumn('marketing_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
