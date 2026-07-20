<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only ledger of every signature-valid money webhook delivery (H1359).
 *
 * WebhookController::handleTochkaWebhook грантит доступ по слову банка: до сих пор
 * не было ни идемпотентности по телу события, ни следа отклонённых доставок.
 * Эта таблица — журнал: одна строка на уникальную доставку (event_hash), с
 * решением, что с ней сделали (applied / duplicate / rejected_resurrection /
 * rejected_amount_mismatch / unmatched). Только created_at — записи не
 * редактируются (паттерн PaymentAudit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->default('tochka');
            // nullable: подписанная доставка без совпавшего локального платежа
            // (нет номера заказа / платёж не найден) тоже журналируется.
            $table->unsignedBigInteger('payment_id')->nullable()->index();
            // sha256 сырого JWT-тела — идемпотентность повторных доставок.
            $table->string('event_hash', 64)->unique();
            $table->string('bank_status')->nullable();
            $table->decimal('reported_amount', 10, 2)->nullable();
            // applied | duplicate | rejected_resurrection | rejected_amount_mismatch | unmatched
            $table->string('decision');
            // Только момент записи; лог неизменяем (public const UPDATED_AT = null).
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_events');
    }
};
