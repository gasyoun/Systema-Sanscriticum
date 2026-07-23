<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H1452 (Wave 4, Anton ops-gaps): «LectureClip» — один вырезанный n8n/ffmpeg
 * фрагмент лекции, загруженный в VK Video/Clips. Аддитивная таблица, флаг
 * clip_marketing управляет только исходящим/входящим вебхуками, не схемой.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lecture_clips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('start_seconds');
            $table->unsignedInteger('end_seconds');
            $table->string('title');
            $table->string('vk_video_id')->nullable();
            $table->string('vk_owner_id')->nullable();
            $table->boolean('is_free')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['lesson_id', 'is_free']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lecture_clips');
    }
};
