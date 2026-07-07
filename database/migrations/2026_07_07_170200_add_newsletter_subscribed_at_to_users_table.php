<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Отметка «подписан на рассылку» (H324). Аддитивная: NULL = не подписчик.
 * Наличие даты открывает «полку подписчика» в кабинете и НЕ влияет на платный
 * доступ (группы/оплаты/тарифы не трогаются).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('newsletter_subscribed_at')->nullable()->after('wants_email_announcements');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('newsletter_subscribed_at');
        });
    }
};
