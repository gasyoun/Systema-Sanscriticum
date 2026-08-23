<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Подарочные сертификаты (H3334): тариф «в подарок» + одноразовый код
 * активации поверх существующей тарифной модели.
 *
 * Деньги записываются ОДИН раз — на платеже покупателя (payments.tariff='gift').
 * Эта таблица хранит сам сертификат: снимок того, ЧТО подарено (курс/блок/
 * членство + ключ доступа), и одноразовый код — ТОЛЬКО как sha256-хэш.
 * Сырой код нигде не персистится: ни в БД, ни в логах. code_hint (последние
 * 4 знака) — только для поддержки опознать сертификат по слову клиента.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gift_certificates', function (Blueprint $table) {
            $table->id();

            // Один сертификат на платёж-покупку (idempotent против повторных paid-переходов).
            $table->foreignId('payment_id')->unique()->constrained()->cascadeOnDelete();

            // Что подарено: курс (null у членства) и ключ доступа из Tariff::accessKey().
            // Активация пишет РОВНО этот ключ в новый payment получателя — доступ
            // открывается через обычный PaymentObserver::grantAccess(), не мимо модели.
            $table->foreignId('course_id')->nullable()->constrained()->nullOnDelete();
            $table->string('tariff_key', 64);
            $table->string('tariff_title');
            $table->decimal('price', 10, 2)->default(0);

            // Диапазон блоков bundle-тарифа (как в payments.start_block/end_block) —
            // активация копирует их в платёж получателя, чтобы BlockAccessMaterializer
            // дорисовал ключи диапазона тем же путём, что и обычная покупка.
            $table->unsignedInteger('start_block')->nullable();
            $table->unsignedInteger('end_block')->nullable();

            // Одноразовый код: sha256 нормализованного кода; уникальность — и защита
            // от коллизий, и быстрый lookup при активации без перебора.
            $table->string('code_hash', 64)->unique();
            $table->string('code_hint', 8);

            // Публичный номер для PDF/верификации (/gift/verify/{number}).
            $table->string('number', 32)->unique();

            // active → activated | revoked (возврат оплаты покупателя).
            $table->string('status', 20)->default('active');

            $table->foreignId('activated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('recipient_payment_id')->nullable();

            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_certificates');
    }
};
