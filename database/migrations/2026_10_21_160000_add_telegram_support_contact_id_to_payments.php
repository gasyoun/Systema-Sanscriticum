<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 21-10-2026: контакт лички, которому бот выдал ссылку на оплату группы.
 * Студент без привязки Telegram к кабинету оплачивает как гость; после оплаты
 * контакт привязывается к плательщику, и ссылка-заявка в чат уходит в ту же
 * личку (SendGroupChatInviteJob). Только для платежей по подписанной ссылке бота.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('telegram_support_contact_id')->nullable()->after('group_id')
                ->constrained('telegram_support_contacts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('telegram_support_contact_id');
        });
    }
};
