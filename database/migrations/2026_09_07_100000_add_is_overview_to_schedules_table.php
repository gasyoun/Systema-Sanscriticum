<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H4328: флаг «Обзорное занятие (не в счёт N)» — строка расписания, которая
 * выводится в полном посте расписания отдельным блоком и не участвует
 * в нумерации занятий. Обзорное ≠ пробное (trial_schedule_id может указывать
 * и на запись), поэтому отдельный boolean, а не переиспользование.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedules', function (Blueprint $table): void {
            $table->boolean('is_overview')->default(false)->after('course_id');
        });
    }

    public function down(): void
    {
        Schema::table('schedules', function (Blueprint $table): void {
            $table->dropColumn('is_overview');
        });
    }
};
