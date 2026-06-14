<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Структурный снимок расчёта выплаты (калькулятор по блоку/группе):
 * база за блок, коэффициент, процент препода, допзанятия. Для аудита и
 * показа разбивки в истории выплат.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teacher_payouts', function (Blueprint $table) {
            $table->json('breakdown')->nullable()->after('salary_value');
        });
    }

    public function down(): void
    {
        Schema::table('teacher_payouts', function (Blueprint $table) {
            $table->dropColumn('breakdown');
        });
    }
};
