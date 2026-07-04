<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Вопросы-ответы по конкретному курсу для блока «FAQ» на продающей странице.
 * Пустая таблица для курса → секция FAQ просто не выводится (graceful degradation).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_faqs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('question');
            $table->text('answer');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->timestamps();

            $table->index(['course_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_faqs');
    }
};
