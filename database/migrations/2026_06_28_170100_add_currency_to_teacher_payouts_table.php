<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Снимок валютной конвертации выплаты: валюта, курс PayPal (₽ за 1 единицу) и
 * сумма в валюте на момент расчёта. Справочно — рублёвая сумма (amount) остаётся
 * источником правды для «Финансов» и сводки ЗП.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teacher_payouts', function (Blueprint $table) {
            $table->string('payout_currency', 3)->nullable()->after('salary_value');
            $table->decimal('exchange_rate', 12, 4)->nullable()->after('payout_currency');
            $table->decimal('amount_foreign', 12, 2)->nullable()->after('exchange_rate');
        });
    }

    public function down(): void
    {
        Schema::table('teacher_payouts', function (Blueprint $table) {
            $table->dropColumn(['payout_currency', 'exchange_rate', 'amount_foreign']);
        });
    }
};
