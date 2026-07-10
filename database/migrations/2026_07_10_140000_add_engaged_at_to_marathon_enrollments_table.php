<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H440/H483 Phase 3b — actual tap-choice completion timestamps, distinct
 * from day{N}_completed_at (H446/H464/H471 = content DELIVERED, drives
 * marathon:deliver-due's idempotent send-guard — must not be repurposed).
 * day1_engaged_at/day2_engaged_at = enrollee finished the tap sequence on
 * that day's page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marathon_enrollments', function (Blueprint $table) {
            $table->timestamp('day1_engaged_at')->nullable()->after('day1_completed_at');
            $table->timestamp('day2_engaged_at')->nullable()->after('day2_completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('marathon_enrollments', function (Blueprint $table) {
            $table->dropColumn(['day1_engaged_at', 'day2_engaged_at']);
        });
    }
};
