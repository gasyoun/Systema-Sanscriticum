<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H440 — 3-day diagnostic marathon. One row per registrant. Deliberately
 * NOT merged into Lead (which already tracks contact/UTM/funnel-status) or
 * WaitlistEntry (which bridges a Lead to a specific future course intake,
 * not a multi-day self-paced drip) — see docs/support-subsystem-map.md's
 * "build a read layer, don't merge tables" precedent, same principle here.
 * day0_started_at is the PERSONAL clock the drip engine (Phase 2) keys off
 * of — never a shared calendar date, per the anti-urgency design in
 * Uprava/custdev/MARATHON_DIAGNOSTIC_2026.md §3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marathon_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->string('track', 16)->default('free'); // free | paid
            $table->string('quiz_goal', 32)->nullable(); // grammar | yoga | philo | try
            $table->text('day2_question')->nullable(); // student's question for the Day 3 live consultation
            $table->timestamp('day0_started_at');
            $table->timestamp('day1_completed_at')->nullable();
            $table->timestamp('day2_completed_at')->nullable();
            $table->timestamp('consultation_booked_at')->nullable();
            $table->timestamps();

            $table->index(['track', 'day0_started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marathon_enrollments');
    }
};
