<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H4818 (R2609-01) — F2 rung-placement result on the trial Deal. Additive,
 * nullable; existing rows unaffected. Written only by
 * TrialBookingService::recordPlacementRung(), gated by features.f2_placement_quiz
 * (default OFF). Never read/written by Payment or TochkaPaymentService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->string('placement_rung')->nullable()->after('trial_outcome');

            $table->index('placement_rung');
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropIndex(['placement_rung']);
            $table->dropColumn('placement_rung');
        });
    }
};
