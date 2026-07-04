<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Пивот: какие отзывы из библиотеки показаны на каком курсе и в каком порядке.
 * unique(course_id, testimonial_id) — один отзыв не прикрепляется к курсу дважды.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_testimonial', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('testimonial_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['course_id', 'testimonial_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_testimonial');
    }
};
