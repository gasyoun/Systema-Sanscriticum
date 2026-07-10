<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H445 Phase 2 — level-quiz score (0..N correct) for the January deva
 * cohort, layered on top of the existing intent-quiz (`quiz_goal`). Null
 * until taken; the `zero` (August) cohort never takes this quiz — see
 * MarathonController::levelQuiz().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marathon_enrollments', function (Blueprint $table) {
            $table->unsignedTinyInteger('quiz_level')->nullable()->after('quiz_goal');
        });
    }

    public function down(): void
    {
        Schema::table('marathon_enrollments', function (Blueprint $table) {
            $table->dropColumn('quiz_level');
        });
    }
};
