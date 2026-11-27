<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «Книжный клуб» → магазин (H8412): каталог книг с остатком.
 *
 * books.stock — ЕДИНСТВЕННЫЙ источник остатка (решение Q6/Q9 грилла
 * 17-11-2026). Остаток ведётся в Filament-админке (BookResource); при 0
 * витрина сама показывает «под заказ 2–3 недели», заказ при этом принимается.
 *
 * Стартовый тираж — книга «Школьные лекции» по 1000 ₽ (цена продажи нашего
 * остатка кампании planeta 219924, решение Q5). Остаток при деплое неизвестен —
 * 0 («под заказ»), фактическое число вносит MG в админке.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('books', function (Blueprint $table) {
            $table->id();

            $table->string('title');
            $table->string('slug')->unique();

            // Цена продажи за экземпляр (₽). 1000 ₽ — решение Q5, не наценка
            // к кампании: это цена нашего остатка тиража.
            $table->decimal('price', 10, 2);

            // Единственный источник остатка. 0 и меньше — «под заказ».
            $table->integer('stock')->default(0);

            // Вес одной книги, г — нужен для расчёта посылок по факту.
            // Пока неизвестен: заполнить из паспорта тиража (Known unknowns).
            $table->unsignedInteger('weight_g')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        DB::table('books')->insert([
            'title' => 'Школьные лекции',
            'slug' => 'shkolnye-lekcii',
            'price' => 1000.00,
            'stock' => 0,
            'weight_g' => null,
            'is_active' => true,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('books');
    }
};
