<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_inline_images', function (Blueprint $table) {
            $table->id();

            $table->foreignId('article_id')
                  ->constrained('articles')
                  ->cascadeOnDelete();

            // FK к таблице медиа Curator. Она у тебя называется "media".
            $table->foreignId('media_id')
                  ->constrained('media')
                  ->cascadeOnDelete();

            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->unique(['article_id', 'media_id']); // одна картинка не дублируется в одной статье
            $table->index(['article_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_inline_images');
    }
};