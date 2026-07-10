<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H440 Phase 6 — 13-day warm-tail auto-series (Days 4–16 off the personal
 * day0_started_at clock, custdev median time-to-purchase). One counter
 * column, not 13 boolean day{N}_sent_at columns like Phase 1–3's drip —
 * the warm-tail is a strictly monotonic day-by-day progression (no
 * branching, no re-visitable days), so "highest day already sent" is
 * sufficient idempotency state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marathon_enrollments', function (Blueprint $table) {
            $table->unsignedTinyInteger('warm_tail_last_day_sent')->nullable()->after('recording_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('marathon_enrollments', function (Blueprint $table) {
            $table->dropColumn('warm_tail_last_day_sent');
        });
    }
};
