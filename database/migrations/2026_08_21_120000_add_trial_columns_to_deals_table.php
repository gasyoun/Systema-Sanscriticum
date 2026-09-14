<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H3247 — trial funnel object on Deal (kind / schedule / trial_source / trial_outcome).
 * Additive. Existing rows stay kind=course. No backfill: payments.trial predicate
 * exists, but historical Deals were never opened for trial SKUs (isCourseSaleShape
 * excludes them); inventing trial kind on course Deals would be a silent rewrite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->string('kind')->default('course');
            $table->foreignId('schedule_id')->nullable()->constrained('schedules')->nullOnDelete();
            $table->string('trial_source')->nullable();
            $table->string('trial_outcome')->nullable();

            $table->index(['kind', 'trial_outcome']);
            $table->index('schedule_id');
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropIndex(['kind', 'trial_outcome']);
            $table->dropConstrainedForeignId('schedule_id');
            $table->dropColumn(['kind', 'trial_source', 'trial_outcome']);
        });
    }
};
