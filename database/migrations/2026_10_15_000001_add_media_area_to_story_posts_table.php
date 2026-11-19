<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H6678: per-story media_area override для stories:publish-story.
 *
 * До сих пор вся persona-полоса жила одним легаси-прямоугольником
 * (StoryPublisher::LEGACY_MEDIA_AREA) — у pré-burned видео с отрисованной
 * плашкой (video-anons, 1474_plaque_v3.mp4, box 40,307 281x90 на 360x640)
 * не было способа нести свою клик-зону. Колонка — NULL = легаси; значение —
 * валидный center-прямоугольник (MediaAreaMath::isValidArea), издаёт
 * StoriesPublishStoryCommand::sendPost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('story_posts', function (Blueprint $table): void {
            $table->json('media_area')->nullable()->after('repeat_rule');
        });
    }

    public function down(): void
    {
        Schema::table('story_posts', function (Blueprint $table): void {
            $table->dropColumn('media_area');
        });
    }
};
