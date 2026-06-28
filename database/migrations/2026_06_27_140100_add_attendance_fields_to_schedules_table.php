<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            // UUID конкретного запуска recurring-встречи Zoom. При единой ссылке
            // курса meeting_id один на все даты — занятие к запуску привязываем по
            // этому uuid (проставляется при первом событии участника/сверке).
            if (! Schema::hasColumn('schedules', 'zoom_occurrence_uuid')) {
                $table->string('zoom_occurrence_uuid')->nullable()->index()->after('zoom_meeting_id');
            }

            // Отметка, что отсутствующим уже разослали уведомление о пропуске
            // (дедуп classes:notify-absent). Сбрасывается при переносе start.
            if (! Schema::hasColumn('schedules', 'absent_notified_at')) {
                $table->timestamp('absent_notified_at')->nullable()->after('reminded_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            foreach (['zoom_occurrence_uuid', 'absent_notified_at'] as $column) {
                if (Schema::hasColumn('schedules', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
