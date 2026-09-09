<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H4456 — окна доступа «студент × курс».
 *
 * Рулинг MG 09-09-2026 (вербатим): «сказать 18 дней и отрезать на 19й день,
 * не надо к курсам Парибка вечный доступ, если не оговорено конкретно у кого
 * такой исключение и вечный доступ».
 *
 * Строка на (user, course):
 *  - ends_at = дата/время отсечки — после неё реальные paid-платежи курса
 *    перестают открывать уроки (Payment::scopeWithoutExpiredAccessWindow);
 *    строки платежей и суммы НЕ трогаются;
 *  - ends_at = NULL — «вечный доступ по именному исключению» (прямое
 *    разрешение, документированное строкой);
 *  - нет строки — прежнее поведение (реальный платёж открывает навсегда).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_access_windows', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('course_id')->index();
            $table->dateTime('ends_at')->nullable();
            $table->string('reason')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'course_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_access_windows');
    }
};
