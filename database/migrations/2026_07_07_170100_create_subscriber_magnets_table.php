<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Полка подписчика» (H324): бесплатные лид-магниты, которые видит подписчик
 * рассылки в личном кабинете и получает в письме. Админ-редактируемо в Filament.
 *
 * ВАЖНО: это НЕ система доступа к платным урокам. Полка ортогональна key-based
 * доступу (Tariff::accessKey / PaymentObserver::grantAccess) — тут только
 * свободные файлы/ссылки, показываемые пользователю с newsletter_subscribed_at.
 * Магнит может быть файлом (`file_path` на public-диске) ИЛИ внешней ссылкой
 * (`url`) — рендерер выбирает по тому, что заполнено.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriber_magnets', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('file_path')->nullable();
            $table->string('url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriber_magnets');
    }
};
