<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H2381 — conversation-level topic history for required close-topic workflow.
 *
 * Distinct from SupportTopicAssignment (daily rollup analytics). One row per
 * assignment event; current topic = latest row for the conversation. Reopen
 * does not delete history. Explicit categories include other/uncategorized.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_conversation_topics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_conversation_id')
                ->constrained('support_conversations')
                ->cascadeOnDelete();
            $table->string('category', 64);
            $table->string('source', 32)->default('manual');
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->index(['support_conversation_id', 'id'], 'sct_conversation_latest_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_conversation_topics');
    }
};
