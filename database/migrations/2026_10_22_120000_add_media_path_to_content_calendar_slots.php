<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 22-10-2026: картинка или видео к запланированному посту календаря контента.
 * Файл лежит на диске `public`; тип (photo/video) выводится из расширения,
 * n8n получает публичный URL и сам грузит его во ВКонтакте.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_calendar_slots', function (Blueprint $table) {
            $table->string('media_path')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('content_calendar_slots', function (Blueprint $table) {
            $table->dropColumn('media_path');
        });
    }
};
