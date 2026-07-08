<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Отзывы для общесайтовой полосы доверия (H323, social proof): помимо привязки
 * к курсам (пивот course_testimonial) отзыв можно пометить «избранным» — он
 * попадает в блок отзывов на витрине каталога. Аддитивно, по умолчанию false.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('testimonials', function (Blueprint $table) {
            $table->boolean('is_featured')->default(false)->after('is_visible');
        });
    }

    public function down(): void
    {
        Schema::table('testimonials', function (Blueprint $table) {
            $table->dropColumn('is_featured');
        });
    }
};
