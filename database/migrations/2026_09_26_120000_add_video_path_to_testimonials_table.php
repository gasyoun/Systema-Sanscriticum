<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Видео-отзыв, загруженный файлом (студент в /dvaram/otzyv или модератор в админке),
 * лежит на диске public в testimonials/videos. media_url остаётся для внешней
 * ссылки (VK, YouTube, Rutube); на сайте загруженный файл важнее ссылки —
 * см. Testimonial::mediaLink(). Аддитивно.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('testimonials', function (Blueprint $table) {
            $table->string('video_path')->nullable()->after('media_url');
        });
    }

    public function down(): void
    {
        Schema::table('testimonials', function (Blueprint $table) {
            $table->dropColumn('video_path');
        });
    }
};
