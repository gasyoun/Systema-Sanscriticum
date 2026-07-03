<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Колонка settled_amount на teacher_payouts. Код авто-зачёта авансов
 * (TeacherSalaryService, Filament TeacherSalaries) уже читает её —
 * COALESCE(settled_amount, 0) в агрегате «сколько аванса ещё не закрыто», —
 * но миграция, добавляющая колонку, не была закоммичена вместе с кодом, из-за
 * чего CI на main падал: «no such column: settled_amount» (issue #271).
 *
 * Additive и обратно совместима: default 0, ретро-платежи трактуются как
 * «ничего не зачтено», что и есть корректное состояние для старых авансов.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teacher_payouts', function (Blueprint $table) {
            $table->decimal('settled_amount', 10, 2)->default(0)->after('settled_by');
        });
    }

    public function down(): void
    {
        Schema::table('teacher_payouts', function (Blueprint $table) {
            $table->dropColumn('settled_amount');
        });
    }
};
