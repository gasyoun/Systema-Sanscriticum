<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * График признания выручки по методу начисления (accrual). Каждая строка —
 * доля суммы одного платежа, признаваемая в конкретном календарном месяце
 * 'YYYY-MM' (месяц идущего блока курса; для разового/короткого продукта —
 * месяц оплаты). Σ строк одного платежа = сумме платежа: accrual лишь
 * перераспределяет кассу по месяцам курса, не меняя итога за всё время.
 *
 * Субледжер, а не источник истины по деньгам: пересобираем идемпотентно из
 * платежей (RevenueScheduleService::regenerateFor / команда
 * revenue:backfill-schedule), поэтому дрейф лечится полным пересбором.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('revenue_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            // Месяц признания в формате 'YYYY-MM' (строка, как salary_recognition_month).
            $table->string('period_month', 7);
            $table->decimal('amount', 12, 2);
            $table->timestamps();

            // Одна строка на (платёж, месяц) — регенерация делает upsert/replace.
            $table->unique(['payment_id', 'period_month']);
            // Свод по месяцу (ОПиУ-начисление) бьёт по period_month.
            $table->index('period_month');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revenue_schedules');
    }
};
