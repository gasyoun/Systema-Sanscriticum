<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H4281: видео-анонс курса — ссылка на YouTube/RuTube/VK video, показывается
 * в hero-блоке продающей страницы вместо статичной обложки. Провайдер
 * распознаётся и валидируется через App\Support\VideoEmbed (уже умеет эти три).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->string('video_announce_url', 1024)->nullable()->after('image_path');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn('video_announce_url');
        });
    }
};
