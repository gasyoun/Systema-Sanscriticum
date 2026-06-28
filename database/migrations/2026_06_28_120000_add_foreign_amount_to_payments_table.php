<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Сумма платежа в валюте (USD/EUR) — справочное поле «для отчётов».
 * Заполняется вручную в админке параллельно рублёвой сумме, нигде в расчётах
 * (ЗП, доступы, скидки, прана) НЕ участвует — только выгружается в колонку
 * «Примечание» финансовой Google-таблицы (SendPaymentToSheetJob).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('foreign_amount', 12, 2)->nullable()->after('amount');
            $table->string('foreign_currency', 3)->nullable()->after('foreign_amount');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['foreign_amount', 'foreign_currency']);
        });
    }
};
