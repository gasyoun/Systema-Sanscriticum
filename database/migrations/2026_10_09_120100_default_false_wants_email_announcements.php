<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 152-ФЗ / 38-ФЗ: согласие на рекламную email-рассылку — только явное.
     * Дефолт колонки был true, и любой путь создания пользователя без галочки
     * (марафон, депозит, заявки на оплату, донаты) молча подписывал человека.
     * Теперь дефолт false — как у wants_messenger_announcements (2026_07_21).
     * Существующие строки НЕ трогаем (решение 09-10-2026: старым подписчикам
     * рассылку не выключаем, у них есть отписка и переключатель в кабинете).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('wants_email_announcements')->default(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('wants_email_announcements')->default(true)->change();
        });
    }
};
