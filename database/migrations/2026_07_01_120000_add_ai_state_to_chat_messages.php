<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            // Состояние ИИ-ответа (suggested/sent/…), симметрично TelegramSupportMessage.
            // direction/responder_type НЕ храним — они выводятся из role в UnifiedMessage.
            $table->string('ai_state', 24)->nullable()->after('is_read');
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropColumn('ai_state');
        });
    }
};
