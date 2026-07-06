<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Операционные расходы (opex) — недостающая расходная сторона ОПиУ.
     * Маркетинг и ЗП живут в своих таблицах; здесь — эквайринг, хостинг,
     * подрядчики, налоги и прочее.
     */
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();

            // Дата траты — кассовый признак для ДДС и ОПиУ по периоду
            $table->date('spent_at')->index();

            // Статья расхода (chart of accounts) — значения из App\Enums\ExpenseCategory
            $table->string('category')->index();

            // Сумма в рублях с копейками (единый с денежным ядром decimal:2)
            $table->decimal('amount', 10, 2);

            // Контрагент (необязательно): банк, хостер, подрядчик
            $table->string('counterparty')->nullable();

            // Комментарий к расходу
            $table->string('note')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
