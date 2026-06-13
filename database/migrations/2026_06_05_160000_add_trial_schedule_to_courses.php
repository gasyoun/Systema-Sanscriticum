<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            // Событие расписания (живое занятие), на которое попадает купивший пробное.
            // Из него берётся дата и Zoom-ссылка; trial_lesson_id (заготовка) синхронизируется
            // автоматически при сохранении курса.
            $table->foreignId('trial_schedule_id')->nullable()->after('trial_lesson_id')
                ->constrained('schedules')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('trial_schedule_id');
        });
    }
};
