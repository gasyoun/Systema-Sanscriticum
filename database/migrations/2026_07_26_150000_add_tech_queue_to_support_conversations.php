<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ops-очередь «Техника» для userbot Helpdesk: queue + peer исходного вопроса
 * (ЛС или учебная группа), чтобы ответ ушёл в тот же chat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_conversations', function (Blueprint $table) {
            $table->string('queue', 32)->nullable()->after('status'); // technical | general | null
            $table->bigInteger('source_telegram_chat_id')->nullable()->after('assigned_to');
            $table->bigInteger('source_telegram_message_id')->nullable()->after('source_telegram_chat_id');
            $table->string('source_chat_type', 32)->nullable()->after('source_telegram_message_id');

            $table->index(['queue', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('support_conversations', function (Blueprint $table) {
            $table->dropIndex(['queue', 'status']);
            $table->dropColumn([
                'queue',
                'source_telegram_chat_id',
                'source_telegram_message_id',
                'source_chat_type',
            ]);
        });
    }
};
