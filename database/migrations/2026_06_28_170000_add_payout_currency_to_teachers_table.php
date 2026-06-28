<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Валюта выплаты преподавателю (EUR/USD) для тех, кому платим через PayPal.
 * NULL = выплаты только в ₽. Справочно: влияет лишь на конвертацию суммы выплаты
 * и отчёт, в рублёвых расчётах ЗП ничего не меняет.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            $table->string('payout_currency', 3)->nullable()->after('requisites');
        });
    }

    public function down(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            $table->dropColumn('payout_currency');
        });
    }
};
