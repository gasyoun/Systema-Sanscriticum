<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Запись в конкретную группу из лички (20-10-2026).
 *
 *  - payments.group_id — группа, которую студент выбрал в диалоге (ссылка на
 *    оплату несёт подписанный параметр). Нужна курсам с несколькими группами
 *    (набор 2026: суббота/вторник), где grantAccess() иначе выдаёт все.
 *  - payments.chat_invite_sent_at — ссылка-заявка в чат группы отправлена
 *    (идемпотентность поверх TelegramSendGuard: ретрай вебхука не шлёт второй раз).
 *  - groups.telegram_join_link — постоянная ссылка с creates_join_request,
 *    создаётся лениво ботом @zapisi_ORSbot; вход одобряет он же по оплате.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('group_id')->nullable()->after('course_id')
                ->constrained('groups')->nullOnDelete();
            $table->timestamp('chat_invite_sent_at')->nullable();
        });

        Schema::table('groups', function (Blueprint $table) {
            $table->string('telegram_join_link')->nullable()->after('telegram_chat_id');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('group_id');
            $table->dropColumn('chat_invite_sent_at');
        });

        Schema::table('groups', function (Blueprint $table) {
            $table->dropColumn('telegram_join_link');
        });
    }
};
