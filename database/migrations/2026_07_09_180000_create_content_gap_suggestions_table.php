<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CAI3 (docs/ROADMAP_CONTENT_AI_2026_2027.md) — one row per (category, window)
 * snapshot from `content:detect-gaps`, re-running the same window upserts in
 * place rather than accumulating duplicate snapshots.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_gap_suggestions', function (Blueprint $table) {
            $table->id();
            $table->string('category');
            $table->date('window_since');
            $table->date('window_until');
            $table->unsignedInteger('chat_days')->default(0);
            $table->unsignedInteger('queries')->default(0);
            $table->unsignedInteger('human_replies')->default(0);
            $table->unsignedInteger('ai_sent')->default(0);
            $table->unsignedInteger('unanswered')->default(0);
            $table->unsignedInteger('curator_minutes')->default(0);
            $table->decimal('auto_rate', 4, 3)->nullable();
            $table->unsignedInteger('deflection_score')->default(0);
            $table->boolean('has_content')->default(false);
            $table->string('status', 16)->default('open'); // open | dismissed (CAI4 digest review)
            $table->timestamps();

            $table->unique(['category', 'window_since', 'window_until'], 'content_gap_suggestion_window_unique');
            $table->index(['status', 'deflection_score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_gap_suggestions');
    }
};
