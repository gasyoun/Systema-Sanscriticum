<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Готовые медиа VK для слота календаря: «photo-1_2,video-1_3» — уже лежат в
 * VK и прикрепляются к wall.post без загрузки (у токена нет прав photos/video).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_calendar_slots', function (Blueprint $table) {
            $table->string('vk_attachments', 500)->nullable()->after('media_path');
        });
    }

    public function down(): void
    {
        Schema::table('content_calendar_slots', function (Blueprint $table) {
            $table->dropColumn('vk_attachments');
        });
    }
};
