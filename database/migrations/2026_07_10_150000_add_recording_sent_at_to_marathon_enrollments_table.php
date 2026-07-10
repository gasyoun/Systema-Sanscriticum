<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H440/H487 Phase 5 — idempotency guard for marathon:deliver-recording
 * (mirrors the shape of day1_completed_at/day2_completed_at/consultation_booked_at:
 * a one-time delivery marker, not an engagement marker).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marathon_enrollments', function (Blueprint $table) {
            $table->timestamp('recording_sent_at')->nullable()->after('consultation_booked_at');
        });
    }

    public function down(): void
    {
        Schema::table('marathon_enrollments', function (Blueprint $table) {
            $table->dropColumn('recording_sent_at');
        });
    }
};
