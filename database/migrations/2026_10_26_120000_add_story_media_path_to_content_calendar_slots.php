<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Вертикальная картинка для сторис, которая уходит вместе с постом слота:
 * в аккаунт Telegram Business и в сторис VK-сообществ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_calendar_slots', function (Blueprint $table) {
            $table->string('story_media_path')->nullable()->after('vk_attachments');
        });
    }

    public function down(): void
    {
        Schema::table('content_calendar_slots', function (Blueprint $table) {
            $table->dropColumn('story_media_path');
        });
    }
};
