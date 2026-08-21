<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H3247 — trial Deal columns (ARCHITECTURE §2). Additive.
 * Existing rows stay kind='course'. No historical backfill: payments.trial
 * SKU is identifiable, but tagging live Deals is the observer's job once
 * the staff flag is on; silent backfill would rewrite funnel history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->string('kind')->default('course')->after('installment_group_id');
            $table->foreignId('schedule_id')->nullable()->after('kind')
                ->constrained('schedules')->nullOnDelete();
            $table->string('trial_source')->nullable()->after('schedule_id');
            $table->string('trial_outcome')->nullable()->after('trial_source');

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
