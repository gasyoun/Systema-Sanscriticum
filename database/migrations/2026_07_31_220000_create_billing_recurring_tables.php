<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H2026 Phase 0 — Tochka multi-mode recurring scaffold (architecture §4).
 *
 * Additive only: no live charges, no student UI. Feature flags stay OFF.
 * Tables: billing_profiles, billing_subscriptions, billing_commitments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            // per_course | consolidated — A/B preference for course streams
            $table->string('preferred_mode', 32)->default('per_course');
            // monthly_1 | monthly_2
            $table->string('consolidated_cadence', 32)->nullable();
            $table->string('tochka_consumer_id')->nullable();
            // 1–28 for consolidated; null → join day of first commitment
            $table->unsignedTinyInteger('default_charge_day')->nullable();
            $table->timestamps();
        });

        Schema::create('billing_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // per_course | consolidated | club | installment | multi_month
            $table->string('mode', 32);
            $table->string('provider', 32)->default('tochka');
            $table->string('provider_subscription_id')->nullable()->index();
            // pending_first_pay | active | past_due | cancelled | completed
            $table->string('status', 32)->default('pending_first_pay')->index();
            // Day | Month | Year (Tochka period enum)
            $table->string('period', 16)->nullable();
            $table->unsignedInteger('tranche_count')->nullable();
            $table->decimal('amount_rub', 12, 2)->nullable();
            // fixed | sum_commitments | installment_due
            $table->string('amount_policy', 32)->default('fixed');
            $table->date('anchor_date')->nullable();
            $table->foreignId('course_id')->nullable()->constrained()->nullOnDelete();
            $table->string('installment_group_id', 64)->nullable()->index();
            $table->unsignedBigInteger('club_product_id')->nullable()->index();
            $table->json('feature_flag_snapshot')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'mode', 'status']);
        });

        Schema::create('billing_commitments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_subscription_id')->nullable()
                ->constrained('billing_subscriptions')->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // course_month | course_block | club_period | installment_row | multi_month_pack
            $table->string('kind', 32);
            $table->foreignId('course_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tariff_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_promise_id')->nullable()
                ->constrained('payment_promises')->nullOnDelete();
            $table->decimal('amount_rub', 12, 2);
            $table->date('covers_from')->nullable();
            $table->date('covers_to')->nullable();
            $table->string('access_key', 64)->nullable();
            $table->unsignedInteger('start_block')->nullable();
            $table->unsignedInteger('end_block')->nullable();
            // scheduled | charged | failed | waived | cancelled
            $table->string('status', 32)->default('scheduled')->index();
            $table->date('due_on')->nullable()->index();
            $table->foreignId('payment_id')->nullable()
                ->constrained('payments')->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status', 'due_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_commitments');
        Schema::dropIfExists('billing_subscriptions');
        Schema::dropIfExists('billing_profiles');
    }
};
