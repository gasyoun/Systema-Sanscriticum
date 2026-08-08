<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H2381 — reuse FollowUpTask for support-dialog follow-ups (no new table).
 *
 * Additive: deal_id becomes nullable so a task can hang off a SupportConversation
 * instead of a CRM Deal. CRM WorkQueue still only loads rows with deal_id set
 * (scoped in WorkQueueReport). Feature flag support_follow_up_tasks is separate
 * from crm_follow_up_tasks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('follow_up_tasks', function (Blueprint $table) {
            $table->dropForeign(['deal_id']);
        });

        Schema::table('follow_up_tasks', function (Blueprint $table) {
            $table->unsignedBigInteger('deal_id')->nullable()->change();
            $table->foreign('deal_id')->references('id')->on('deals')->cascadeOnDelete();

            $table->foreignId('support_conversation_id')
                ->nullable()
                ->after('deal_id')
                ->constrained('support_conversations')
                ->nullOnDelete();
            $table->text('note')->nullable()->after('type');

            $table->index('support_conversation_id');
        });
    }

    public function down(): void
    {
        Schema::table('follow_up_tasks', function (Blueprint $table) {
            $table->dropForeign(['support_conversation_id']);
            $table->dropIndex(['support_conversation_id']);
            $table->dropColumn(['support_conversation_id', 'note']);
            $table->dropForeign(['deal_id']);
        });

        Schema::table('follow_up_tasks', function (Blueprint $table) {
            $table->unsignedBigInteger('deal_id')->nullable(false)->change();
            $table->foreign('deal_id')->references('id')->on('deals')->cascadeOnDelete();
        });
    }
};
