<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Со-преподаватели курса со СВОИМИ условиями ЗП. Основной препод и его ставка
 * остаются на courses (teacher_id/salary_type/salary_value); этот pivot хранит
 * только ДОПОЛНИТЕЛЬНЫХ преподов. Доступ к курсу = основной ИЛИ есть в pivot;
 * начисление ЗП — независимое, у каждого своя ставка от одной выручки.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_teacher', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained()->cascadeOnDelete();
            $table->string('salary_type')->nullable();
            $table->decimal('salary_value', 10, 2)->nullable();
            $table->timestamps();

            $table->unique(['course_id', 'teacher_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_teacher');
    }
};
