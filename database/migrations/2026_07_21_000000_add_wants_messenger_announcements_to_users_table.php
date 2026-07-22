<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Согласие на РЕКЛАМНУЮ рассылку в мессенджеры (Telegram/VK), 152-ФЗ.
        // Зеркалит wants_email_announcements, но с иной политикой дефолта:
        //   • default(false) — новые аккаунты opt-in (согласие берётся из галочки
        //     формы на входе); незаполненный путь создания = false = не шлём =
        //     безопасное направление отказа.
        //   • существующие строки грандфазерятся в true разовым UPDATE ниже
        //     (решение MG 21-07-2026: opt-out для уже привязанных TG/VK, чтобы
        //     текущий охват анонсов не отвалился). Презумпция согласия для
        //     существующих делает механизм отписки (бот /stop) обязательным.
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('wants_messenger_announcements')
                ->default(false)
                ->after('wants_email_announcements');
        });

        // Грандфазер: все УЖЕ существующие пользователи → true (opt-out).
        // Выполняется один раз на проде; на свежей БД/в тестах users пуста → 0 строк,
        // поэтому новые тестовые юзеры видят честный opt-in-дефолт (false).
        DB::table('users')->update(['wants_messenger_announcements' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('wants_messenger_announcements');
        });
    }
};
