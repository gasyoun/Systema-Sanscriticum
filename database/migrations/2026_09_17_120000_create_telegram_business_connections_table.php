<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H5065 — полоса Telegram Business: подключения бота к аккаунту.
 *
 * Зачем отдельная таблица, а не колонка в telegram_support_accounts. Аккаунтов
 * поддержки у школы несколько, а подключений к Telegram Business у ОДНОГО
 * владельца может быть много (смена бота, тестовый бот, второй аккаунт), и
 * каждое приходит своим апдейтом `business_connection` со своим
 * `business_connection_id`. Плоское поле «текущее подключение» на аккаунте
 * затирало бы историю и делало бы откат невосстановимым.
 *
 * Существующие support-таблицы не трогаются ни одной колонкой: полоса
 * добавляется рядом, а не внутрь (fence плана — «no schema change to
 * telegram_support_messages beyond additive nullable columns»).
 *
 * `can_reply` — право отправлять ОТ ИМЕНИ аккаунта. Оно принадлежит владельцу
 * аккаунта и может быть отозвано в любой момент; поэтому и хранится, и
 * проверяется перед каждой отправкой, а не считается раз выданным навсегда.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_business_connections', function (Blueprint $table) {
            $table->id();
            $table->string('business_connection_id')->unique();
            $table->bigInteger('owner_telegram_user_id');
            $table->bigInteger('owner_chat_id')->nullable();
            $table->boolean('can_reply')->default(false);
            $table->boolean('is_enabled')->default(false);
            $table->json('rights')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();

            $table->index(['owner_telegram_user_id', 'is_enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_business_connections');
    }
};
