<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Очередь публикаций канала/сториз @rusamskrtam (H3930, Phase 1):
 * текстовые посты публикует stories:publish-due (Bot API, магнит-бот),
 * photo/video строки ждут Phase 2 (MTProto stories lane). Repeat-rule
 * колонки заложены, движок повтора — Phase 2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('story_posts', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 16)->default('text'); // text | photo | video
            $table->text('payload')->nullable(); // текст поста / подпись к медиа
            $table->string('media_path')->nullable();
            $table->string('source', 16)->default('manual'); // queue | harvest | dm | homework | manual
            $table->string('source_key')->nullable(); // ключ дедупа: имя файла очереди и т.п.
            $table->string('status', 16)->default('draft'); // draft | approved | published | skipped
            $table->timestamp('publish_at')->nullable();
            $table->json('repeat_rule')->nullable(); // {every_days: N, times: M} — Phase 2
            $table->timestamp('posted_at')->nullable();
            $table->unsignedInteger('repeat_count')->default(0);
            $table->string('telegram_message_id')->nullable();
            $table->text('journal')->nullable(); // заметки издателя (скипы, пробы)
            $table->timestamps();

            $table->unique(['source', 'source_key']);
            $table->index(['status', 'publish_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('story_posts');
    }
};
