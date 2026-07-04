<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Библиотека отзывов, переиспользуемая между курсами (привязка — через пивот
 * course_testimonial). vitrina рекомендует держать общую библиотеку и отбирать
 * 3–5 релевантных отзывов на страницу, а не дублировать текст под каждый курс.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('testimonials', function (Blueprint $table) {
            $table->id();
            $table->string('author_name');
            $table->string('city')->nullable();
            $table->string('avatar_path')->nullable();
            $table->text('body');
            $table->unsignedTinyInteger('rating')->nullable(); // 1–5, опционально
            $table->string('media_url')->nullable();           // аудио/видео-отзыв
            $table->boolean('is_visible')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('testimonials');
    }
};
