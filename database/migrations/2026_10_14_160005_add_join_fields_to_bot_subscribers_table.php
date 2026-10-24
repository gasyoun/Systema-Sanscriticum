<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «✍️ Участвовать» в @samskrte_bot (по образцу ботов конференций): подписка
 * на канал с проверкой → согласие → анкета из трёх вопросов → лид в CRM →
 * подарки (полка подписчика). Состояние шага и ответы живут на подписчике.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_subscribers', function (Blueprint $table): void {
            $table->string('join_step', 16)->nullable()->after('menu_sent_at');
            $table->string('join_name', 128)->nullable()->after('join_step');
            $table->string('join_interest', 32)->nullable()->after('join_name');
            $table->string('join_time', 16)->nullable()->after('join_interest');
            $table->string('username', 64)->nullable()->after('first_name');
            $table->timestamp('consent_at')->nullable()->after('join_time');
            $table->timestamp('joined_at')->nullable()->after('consent_at');
            $table->foreignId('lead_id')->nullable()->after('joined_at')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bot_subscribers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('lead_id');
            $table->dropColumn(['join_step', 'join_name', 'join_interest', 'join_time', 'username', 'consent_at', 'joined_at']);
        });
    }
};
