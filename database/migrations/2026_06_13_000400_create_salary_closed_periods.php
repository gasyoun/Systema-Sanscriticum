<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Закрытые периоды ЗП (по преподавателю + месяц). Если месяц закрыт, начисления,
 * которые должны были признаться в нём (поздние оплаты/обещания/рассрочки за уже
 * прошедший блок), переносятся в ближайший открытый месяц (ролл-форвард).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_closed_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained()->cascadeOnDelete();
            $table->string('period_month', 7); // YYYY-MM
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['teacher_id', 'period_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_closed_periods');
    }
};
