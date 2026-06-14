<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ручной override месяца начисления ЗП для платежа (YYYY-MM).
 *
 * По умолчанию ЗП преподавателя признаётся по периоду блока (accrual): платёж
 * раскладывается на оплаченные блоки, доля за блок идёт в ЗП в месяц этого блока.
 * Если у платежа задан salary_recognition_month — вся сумма признаётся в этом
 * месяце, перекрывая авто-расчёт (нестандартные случаи).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('salary_recognition_month', 7)->nullable()->after('end_block');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('salary_recognition_month');
        });
    }
};
