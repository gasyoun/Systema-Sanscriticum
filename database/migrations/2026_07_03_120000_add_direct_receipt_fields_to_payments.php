<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Прямые платежи на ЛИЧНЫЙ счёт преподавателя (минуя кассу школы).
 *
 * received_account       — куда физически пришли деньги: 'school' (касса, дефолт)
 *                          или 'teacher_personal' (личный счёт преподавателя).
 * received_by_teacher_id — какой преподаватель держит деньги (только для
 *                          teacher_personal; null иначе). FK → teachers, nullOnDelete.
 * payer_note             — плательщик/посредник свободным текстом («S. через V.,
 *                          чек №…»). Дата чека = created_at, сумма в валюте =
 *                          foreign_amount/foreign_currency (уже есть).
 *
 * Прямой платёж НЕ является выручкой курса для начисления ЗП (иначе двойной счёт:
 * преподаватель уже держит всю сумму) — исключается в TeacherSalaryService и
 * авто-зачитывается по номиналу в валюте выплаты (directReceiptsForTeacher).
 * Доступ студента и отчёт должников он по-прежнему покрывает.
 *
 * Аддитивно и безопасно на живой таблице: у всех существующих строк
 * received_account = 'school' по умолчанию, бэкфилл не нужен.
 * См. docs/direct-teacher-receipts.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('received_account')->default('school')->after('status');
            $table->foreignId('received_by_teacher_id')->nullable()->after('received_account')
                ->constrained('teachers')->nullOnDelete();
            $table->string('payer_note')->nullable()->after('received_by_teacher_id');
            $table->index(['received_by_teacher_id', 'received_account'], 'payments_direct_receipt_idx');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_direct_receipt_idx');
            $table->dropConstrainedForeignId('received_by_teacher_id');
            $table->dropColumn(['received_account', 'payer_note']);
        });
    }
};
