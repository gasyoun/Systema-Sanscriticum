<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Операционный тред поддержки — reopenable «дело» по пользователю, поверх ОБОИХ
 * хранилищ (см. docs/support-subsystem-map.md). Имя `support_conversations`
 * освободилось после переименования дневного rollup'а в `support_daily_rollups`.
 * Тред не сливает таблицы: он лишь группирует сообщения через nullable FK на
 * chat_messages и telegram_support_messages.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 16)->default('open'); // open | pending | closed
            $table->string('subject')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->foreignId('support_conversation_id')->nullable()->after('id')
                ->constrained('support_conversations')->nullOnDelete();
        });

        Schema::table('telegram_support_messages', function (Blueprint $table) {
            $table->foreignId('support_conversation_id')->nullable()->after('id')
                ->constrained('support_conversations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('telegram_support_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('support_conversation_id');
        });

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('support_conversation_id');
        });

        Schema::dropIfExists('support_conversations');
    }
};
