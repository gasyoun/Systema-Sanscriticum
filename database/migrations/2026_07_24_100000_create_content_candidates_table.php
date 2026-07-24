<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H1547 (Wave 1 of the n8n lecture content engine): единая review/publish-
 * единица для всего, что генерируется из лекций — клипы (этот wave),
 * social/faq/article/study (waves 2-5). Аддитивная таблица; флаг
 * content_from_lectures управляет только генераторами/публикацией, не схемой.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_candidates', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->string('status')->default('draft');
            $table->foreignId('lesson_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lecture_clip_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('content_candidates')->nullOnDelete();
            $table->string('title')->nullable();
            $table->text('body')->nullable();
            $table->text('quote')->nullable();
            $table->string('channel_target')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'status']);
            $table->index('lesson_id');
            $table->index('lecture_clip_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_candidates');
    }
};
