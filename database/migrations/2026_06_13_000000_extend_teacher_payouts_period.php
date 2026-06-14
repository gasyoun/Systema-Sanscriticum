<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Расширяет teacher_payouts для ведомости «Зарплаты»:
 *   - paid_at      — фактическая дата выплаты (раньше была только created_at);
 *   - period_month — за какой месяц выплата (YYYY-MM), для отчётов по периодам;
 *   - course_id    — опциональная привязка выплаты к курсу;
 *   - salary_type / salary_value — снимок ставки курса на момент выплаты,
 *     чтобы последующая смена ставки не «переписывала» закрытые периоды (B6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teacher_payouts', function (Blueprint $table) {
            $table->date('paid_at')->nullable()->after('amount');
            $table->string('period_month', 7)->nullable()->after('paid_at');
            $table->foreignId('course_id')->nullable()->after('period_month')
                ->constrained()->nullOnDelete();
            $table->string('salary_type')->nullable()->after('course_id');
            $table->decimal('salary_value', 10, 2)->nullable()->after('salary_type');
        });

        // Backfill: у существующих выплат датой выплаты считаем дату создания записи.
        DB::table('teacher_payouts')
            ->whereNull('paid_at')
            ->update(['paid_at' => DB::raw('DATE(created_at)')]);
    }

    public function down(): void
    {
        Schema::table('teacher_payouts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('course_id');
            $table->dropColumn(['paid_at', 'period_month', 'salary_type', 'salary_value']);
        });
    }
};
