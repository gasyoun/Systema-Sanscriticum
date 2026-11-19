<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Книжный клуб» (H8195): заказы на доставку книг кампании planeta 219924.
 *
 * Книги оплачены на кампании — здесь только доставка. Связка с деньгами:
 * payments.tariff='book_club_delivery', course_id=null (доступ не выдаётся).
 * Матчинг с экспортом кампании ручной: planeta_email + planeta_order_number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('book_club_orders', function (Blueprint $table) {
            $table->id();
            // Один платёж — один заказ на доставку; платёж может быть ещё не создан
            // только в теории (в коде создаётся в одной транзакции) — nullable для
            // безопасного rollback платежа.
            $table->foreignId('payment_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Матчинг с fulfillment_list.csv кампании (ручная доводка).
            $table->string('planeta_email');
            $table->string('planeta_order_number');

            // Почтовый адрес получателя (нужен для печати этикеток).
            $table->string('recipient_name');
            $table->string('country_code', 8); // RU/LV/IL/DE/US/OTHER (config/book_club.php)
            $table->string('country_name')->nullable(); // свободное имя для OTHER
            $table->string('city');
            $table->string('postal_code', 32);
            $table->string('address_line');
            $table->string('phone', 64)->nullable();

            // Фиксированная цена доставки на момент заказа (тариф из config).
            $table->decimal('amount', 10, 2);

            $table->timestamps();

            // Матчинг и анти-дубль: один планета-заказ — одна оплата доставки.
            $table->index(['planeta_email', 'planeta_order_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_club_orders');
    }
};
