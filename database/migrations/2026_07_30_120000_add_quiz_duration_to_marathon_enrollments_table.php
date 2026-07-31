<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Time spent on Day 1/2 tap-choice quizzes — client-measured, posted with
 * completeDay, visible to the enrollee on the done screen and to admin in
 * Filament (MarathonQuizProgress). Capped at 2h server-side.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marathon_enrollments', function (Blueprint $table) {
            $table->unsignedInteger('day1_quiz_seconds')->nullable()->after('day1_engaged_at');
            $table->unsignedInteger('day2_quiz_seconds')->nullable()->after('day2_engaged_at');
        });
    }

    public function down(): void
    {
        Schema::table('marathon_enrollments', function (Blueprint $table) {
            $table->dropColumn(['day1_quiz_seconds', 'day2_quiz_seconds']);
        });
    }
};
