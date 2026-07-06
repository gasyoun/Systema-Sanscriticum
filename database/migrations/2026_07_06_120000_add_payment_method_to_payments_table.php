<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Способ оплаты (СБП/карта) для точного расчёта комиссии эквайринга в
 * «Юнит-экономике». Точка предлагает оба способа (paymentMode=['sbp','card']);
 * фактически выбранный приходит в вебхуке и до сих пор не сохранялся. NULL =
 * старые платежи и платежи, где способ не распознан — считаются вилкой.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // 'sbp' | 'card' | NULL (неизвестно/legacy).
            $table->string('payment_method', 16)->nullable()->after('provider');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('payment_method');
        });
    }
};
