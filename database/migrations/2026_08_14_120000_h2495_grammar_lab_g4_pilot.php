<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H2495 / G4 — unique entitlement source refs + consented pilot tables.
 * Additive. No feature-flag flip. Empty tables until a human authorizes a cohort.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grammar_lab_entitlements', function (Blueprint $table) {
            $table->unique(['user_id', 'grant_kind', 'source_ref'], 'grammar_lab_entitlements_source_unique');
        });

        Schema::create('grammar_lab_pilot_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('prior_exposure', 32);
            $table->string('cohort', 8);
            $table->timestamp('consented_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();
            $table->unique('user_id');
        });

        Schema::create('grammar_lab_pilot_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('participant_id')->constrained('grammar_lab_pilot_participants')->cascadeOnDelete();
            $table->string('event_type', 32);
            $table->string('topic_id', 160)->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('created_at');
            $table->index(['participant_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grammar_lab_pilot_events');
        Schema::dropIfExists('grammar_lab_pilot_participants');
        Schema::table('grammar_lab_entitlements', function (Blueprint $table) {
            $table->dropUnique('grammar_lab_entitlements_source_unique');
        });
    }
};
