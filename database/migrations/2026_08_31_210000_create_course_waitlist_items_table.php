<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Список ожидания» (MG ruling 31-08-2026): курсы-кандидаты, за которые
 * голосуют зарегистрированные ученики. Когда голоса достигают min_payers,
 * кандидат переходит в payment_open; при нужном количестве оплат к плановой
 * дате создаются живые Schedule-строки, иначе — переносы по лестнице
 * «октябрь → январь (грамматика) / март (прочие) → июль → сентябрь след. года»,
 * цикл 4 попытки из года в год, максимум 4 года (start_attempts до 16).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_waitlist_items', function (Blueprint $table) {
            $table->id();
            // Публичный стабильный идентификатор для фида/виджета (никаких id наружу).
            $table->string('slug')->unique();
            // Денормализованные витринные поля: строка ждёт, пока курс/тариф создаются.
            $table->string('course_title');
            $table->foreignId('course_id')->nullable()->constrained('courses')->nullOnDelete();
            $table->string('teacher_name');
            // Слот вида «пн 18:00», «сб 13:00»; null = время уточняется.
            $table->string('slot')->nullable();
            // «Не раньше» — уважается всегда, включая лестницу переносов.
            $table->date('earliest_start_at')->nullable();
            // Минимум платных участников для старта (default 8).
            $table->unsignedSmallInteger('min_payers')->default(8);
            // Цена одного блока, рублей.
            $table->unsignedInteger('block_price_rub')->nullable();
            // grammar | other — определяет ветку лестницы переносов.
            $table->string('kind')->default('other');
            // collecting → payment_open → payment_deadline_passed → postponed | scheduled | closed.
            $table->string('status')->default('collecting');
            // Номер текущей попытки старта (0..16, 4 попытки × 4 года максимум).
            $table->unsignedTinyInteger('start_attempts')->default(0);
            // Плановая дата текущей попытки (для дедлайна оплаты за 7 дней).
            $table->date('planned_start_at')->nullable();
            // История прошлых потоков (прогноз: спад между потоками 25–60 %).
            $table->unsignedInteger('historical_paid_n')->nullable();
            // Текст вида «1 поток 2025 — 152 ученика, 2 поток 2026 — 90».
            $table->text('historical_notes')->nullable();
            $table->boolean('is_listed')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['status', 'is_listed']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_waitlist_items');
    }
};