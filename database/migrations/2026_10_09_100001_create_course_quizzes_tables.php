<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Квизы этапов курса (мини-курсы из чат-ботов): банк вопросов с вариантами,
 * попытки студента с результатом. Привязка к этапу — по block_number, как у
 * уроков: курс → course_blocks.number → квиз того же номера.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_quizzes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('block_number')->default(1);
            $table->string('title');
            $table->text('description')->nullable();
            // Сколько процентов правильных ответов нужно для зачёта.
            $table->unsignedTinyInteger('pass_score')->default(60);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['course_id', 'block_number']);
        });

        Schema::create('course_quiz_questions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_quiz_id')->constrained('course_quizzes')->cascadeOnDelete();
            $table->text('question');
            // Список вариантов ровно в том порядке, в каком их вводит редактор;
            // на экране порядок перемешивается детерминированно (Support-класс).
            $table->json('options');
            // Индекс правильного варианта в options.
            $table->unsignedTinyInteger('correct_option');
            $table->text('explanation')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('course_quiz_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_quiz_id')->constrained('course_quizzes')->cascadeOnDelete();
            $table->unsignedTinyInteger('score');
            $table->unsignedTinyInteger('total');
            $table->boolean('passed');
            // {'<question_id>': '<выбранный индекс>'} — как у cabinet_mastery_attempts.
            $table->json('answers');
            $table->timestamps();

            $table->index(['user_id', 'course_quiz_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_quiz_attempts');
        Schema::dropIfExists('course_quiz_questions');
        Schema::dropIfExists('course_quizzes');
    }
};
