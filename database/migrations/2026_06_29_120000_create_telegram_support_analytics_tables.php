<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_support_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name')->default('support');
            $table->string('session_path')->nullable();
            $table->string('phone')->nullable();
            $table->string('api_id')->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('telegram_support_chats', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('telegram_chat_id')->unique();
            $table->foreignId('linked_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type')->default('private');
            $table->string('title')->nullable();
            $table->string('username')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
        });

        Schema::create('telegram_support_contacts', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('telegram_user_id')->nullable()->unique();
            $table->foreignId('telegram_support_chat_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('linked_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name')->nullable();
            $table->string('username')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('first_inbound_at')->nullable();
            $table->timestamps();
        });

        Schema::create('support_responder_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('marker_label')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('responder_type')->default('human');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('telegram_support_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_support_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('telegram_support_chat_id')->constrained()->cascadeOnDelete();
            $table->foreignId('telegram_support_contact_id')->nullable()->constrained()->nullOnDelete();
            $table->bigInteger('telegram_chat_id');
            $table->bigInteger('telegram_message_id');
            $table->string('direction', 16);
            $table->string('role', 24)->default('unknown');
            $table->string('responder_type', 24)->nullable();
            $table->foreignId('responder_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('responder_marker')->nullable();
            $table->string('ai_state', 24)->nullable();
            $table->text('text')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('sent_at')->index();
            $table->timestamps();

            $table->unique(
                ['telegram_support_account_id', 'telegram_chat_id', 'telegram_message_id'],
                'telegram_support_message_unique'
            );
            $table->index(['telegram_support_chat_id', 'sent_at']);
            $table->index(['direction', 'sent_at']);
        });

        Schema::create('support_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_support_chat_id')->constrained()->cascadeOnDelete();
            $table->date('conversation_date');
            $table->timestamp('first_message_at')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->unsignedInteger('incoming_count')->default(0);
            $table->unsignedInteger('outgoing_count')->default(0);
            $table->unsignedInteger('human_reply_count')->default(0);
            $table->unsignedInteger('ai_suggested_count')->default(0);
            $table->unsignedInteger('ai_sent_count')->default(0);
            $table->boolean('is_unanswered')->default(false);
            $table->boolean('has_new_contact')->default(false);
            $table->unsignedInteger('first_response_seconds')->nullable();
            $table->timestamps();

            $table->unique(
                ['telegram_support_chat_id', 'conversation_date'],
                'support_conversation_chat_date_unique'
            );
            $table->index(['conversation_date', 'is_unanswered']);
        });

        Schema::create('support_topic_rules', function (Blueprint $table) {
            $table->id();
            $table->string('category');
            $table->json('keywords');
            $table->unsignedInteger('priority')->default(100);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('support_topic_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_conversation_id')->constrained()->cascadeOnDelete();
            $table->string('category');
            $table->string('source', 24)->default('keyword');
            $table->decimal('confidence', 4, 3)->default(0);
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->unique(
                ['support_conversation_id', 'category', 'source'],
                'support_topic_assignment_unique'
            );
        });

        Schema::create('support_ai_reply_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_support_message_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type', 24);
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ai_reply_events');
        Schema::dropIfExists('support_topic_assignments');
        Schema::dropIfExists('support_topic_rules');
        Schema::dropIfExists('support_conversations');
        Schema::dropIfExists('telegram_support_messages');
        Schema::dropIfExists('support_responder_mappings');
        Schema::dropIfExists('telegram_support_contacts');
        Schema::dropIfExists('telegram_support_chats');
        Schema::dropIfExists('telegram_support_accounts');
    }
};
