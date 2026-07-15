<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H987 -- RQ4 study harness (SanskritGrammar docs/RQ4_EVALUATION_PROTOCOL_2026.md).
 * One row per enrolled participant. Arm assignment uses stratified
 * minimisation (protocol Section 2's "randomised, stratified by matching
 * variable, 1:1 allocation") over `prior_exposure` -- see Rq4Participant's
 * assignArm(). Retention window is fixed at 4 weeks (MG's ruling), computed
 * once at post_test completion into `retention_due_at`, not a config knob.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rq4_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('prior_exposure', 20); // none | kochergina | beyond
            $table->char('arm', 1); // A = on-ramp-first, B = talmud-first
            $table->timestamp('consented_at');
            $table->timestamp('pre_test_completed_at')->nullable();
            $table->timestamp('arm_content_started_at')->nullable();
            $table->timestamp('post_test_completed_at')->nullable();
            $table->timestamp('retention_due_at')->nullable();
            $table->timestamp('retention_test_completed_at')->nullable();
            $table->timestamp('retention_reminder_sent_at')->nullable();
            $table->timestamps();

            $table->unique('user_id'); // one enrollment per student
            $table->index(['prior_exposure', 'arm']); // for stratified-assignment lookups
            $table->index('retention_due_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rq4_participants');
    }
};
