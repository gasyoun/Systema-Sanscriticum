<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teacher_payouts', function (Blueprint $table) {
            // Частичный зачёт аванса: сколько из выданного аванса уже зачтено
            // (авто-оффсет прямых платежей в калькуляторе, layer 4/4). null = 0
            // зачтено (COALESCE(settled_amount, 0) в TeacherSalaryService). Колонку
            // читает/пишет код, уже влитый в main (6c41553), но её миграция была
            // забыта — из-за чего teacher-salary тесты падали «no such column» (#271).
            $table->decimal('settled_amount', 10, 2)->nullable()->after('settled_at');
        });
    }

    public function down(): void
    {
        Schema::table('teacher_payouts', function (Blueprint $table) {
            $table->dropColumn('settled_amount');
        });
    }
};
