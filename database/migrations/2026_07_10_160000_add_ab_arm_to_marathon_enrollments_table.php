<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H447 — Saraswati trainer suite Phase 1, §5 leaderboard A/B (design doc
 * Uprava/docs/SARASWATI_TRAINERS_2026.md §5). Deterministic 50/50 hash,
 * assigned once at enrolment and never recomputed per request — the whole
 * point is that a student's arm never flips mid-marathon. Nullable: legacy
 * rows created before this column existed are backfilled lazily by
 * MarathonEnrollment::arm() the first time they're read, not by this
 * migration (no lead_id-keyed backfill is destructive here — see model).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marathon_enrollments', function (Blueprint $table): void {
            $table->string('ab_arm', 1)->nullable()->after('quiz_goal');
            $table->index('ab_arm');
        });
    }

    public function down(): void
    {
        Schema::table('marathon_enrollments', function (Blueprint $table): void {
            $table->dropIndex(['ab_arm']);
            $table->dropColumn('ab_arm');
        });
    }
};
