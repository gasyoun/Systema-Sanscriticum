<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H5134 — «Избранное» (сердечки): личный список курсов + сигнал направлений.
 *
 * Рулинги MG 17-09-2026: хранение — только кабинет (auth); сердечко —
 * лёгкий сигнал «направление интересно», отдельно от голоса ждуна и без
 * записи в очередь. `course_id` — сердечко на карточке курса (/k/{slug},
 * каталог); `waitlist_slug` — сердечко на карточке ждуна без карточки
 * курса (например kosmografiya). Ровно один из двух заполняется.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('course_id')->nullable()->constrained('courses')->nullOnDelete();
            $table->string('waitlist_slug')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'course_id', 'waitlist_slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_favorites');
    }
};
