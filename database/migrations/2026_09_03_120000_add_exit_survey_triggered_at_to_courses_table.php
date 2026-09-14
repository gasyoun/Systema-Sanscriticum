<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            // H3915: момент разбора курса задачей «Exit-опрос» (уведомление
            // куратору с черновиками ушло). Дедуп авто-триггера завершения —
            // как milestones_nudge_sent_at у детектора вех.
            $table->timestamp('exit_survey_triggered_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn('exit_survey_triggered_at');
        });
    }
};
