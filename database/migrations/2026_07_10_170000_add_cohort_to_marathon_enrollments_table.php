<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H445 Phase 1 — распознаёт когорту марафона на уровне энрола: 'zero'
 * (августовская, истинно нулевая, только кириллица — H440 as-built) или
 * 'deva' (январская, деванагари-входная — H445 §1 дельта). Определяется по
 * лендингу регистрации (marathon.cohort_by_landing_slug), не выбирается
 * пользователем — см. MarathonController::resolveCohort().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marathon_enrollments', function (Blueprint $table) {
            $table->string('cohort', 16)->default('zero')->after('track');
        });
    }

    public function down(): void
    {
        Schema::table('marathon_enrollments', function (Blueprint $table) {
            $table->dropColumn('cohort');
        });
    }
};
