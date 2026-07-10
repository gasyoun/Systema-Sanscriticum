<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H440/H471 Phase 4 — timestamp for the ₽500 paid-track checkout actually
 * being paid, distinct from `consultation_booked_at` (Day-3 slot booking,
 * a later phase). Also the idempotency guard against double-charging.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marathon_enrollments', function (Blueprint $table) {
            $table->timestamp('paid_at')->nullable()->after('day2_completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('marathon_enrollments', function (Blueprint $table) {
            $table->dropColumn('paid_at');
        });
    }
};
