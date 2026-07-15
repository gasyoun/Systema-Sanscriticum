<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H987 -- one row per diagnostic-item response. `answered_at` is the raw
 * timestamp; metric 1 (time-to-first-correct-derivation) is derived offline
 * from `arm_content_started_at` (rq4_participants) + the first correct
 * post_test response's `answered_at` -- not computed live in the app, per
 * the protocol's own separation of data collection from analysis.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rq4_responses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('participant_id')->constrained('rq4_participants')->cascadeOnDelete();
            $table->string('phase', 20); // pre_test | post_test | retention_test
            $table->string('item_slp1', 40);
            $table->string('answered_ryad', 10);
            $table->string('answered_tip', 10);
            $table->string('answered_set', 10);
            $table->boolean('correct');
            $table->timestamp('answered_at');
            $table->timestamps();

            $table->unique(['participant_id', 'phase', 'item_slp1']); // one answer per item per phase
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rq4_responses');
    }
};
