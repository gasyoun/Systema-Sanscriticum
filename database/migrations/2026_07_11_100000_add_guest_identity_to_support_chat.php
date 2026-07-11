<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H536 Phase 2 — гостевая идентичность для живого веб-чата поддержки.
 *
 * Анонимный посетитель samskrte.ru владеет своим тредом через session-scoped
 * `guest_token` на support_conversations. Это НЕ внешняя идентичность и не
 * конкурирует с `social_accounts` (см. docs/support-identity.md — «четвёртого
 * маппинга не заводить»): это эфемерный маркер владения на сессию. При логине
 * тред сводится к `user_id`, токен можно обнулить.
 *
 * `chat_messages.user_id` становится nullable: у сообщения гостя нет строки в
 * `users`. Реконсиляция гостевого треда идёт по `support_conversation_id`, не по
 * `user_id`. dbal нужен для change() на Laravel 10 (прецедент —
 * make_landing_page_id_nullable_on_leads, тот же FK->nullable паттерн).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_conversations', function (Blueprint $table) {
            $table->string('guest_token', 64)->nullable()->unique()->after('user_id');
            $table->string('guest_name')->nullable()->after('guest_token');
        });

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });

        Schema::table('support_conversations', function (Blueprint $table) {
            $table->dropUnique(['guest_token']);
            $table->dropColumn(['guest_token', 'guest_name']);
        });
    }
};
