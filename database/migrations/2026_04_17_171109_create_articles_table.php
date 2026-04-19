<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('articles', function (Blueprint $table) {
            $table->id();

            // Связь с рубрикой. nullable — чтобы можно было создать статью без рубрики.
            // onDelete('set null') — при удалении рубрики статьи не теряются.
            $table->foreignId('category_id')
                  ->nullable()
                  ->constrained('article_categories')
                  ->nullOnDelete();

            // URL и заголовки
            $table->string('slug')->unique();     // "sanskrit-for-adults"
            $table->string('title');              // H1
            $table->string('subtitle')->nullable(); // "зачем, как и с чего начать"
            $table->text('excerpt')->nullable();  // анонс для карточки на /s/

            // Медиа и контент
            $table->string('cover_path')->nullable(); // storage/app/public/articles/...
            $table->longText('body');                 // HTML из RichEditor

            // Мета автора
            $table->unsignedSmallInteger('reading_time')->default(5); // минут
            $table->string('author_name')->default('Общество ревнителей санскрита');

            // Публикация
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();

            // SEO (отдельно от title — title для H1, meta_title для <title>)
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 500)->nullable();

            // Денормализованный счётчик для быстрой сортировки/вывода в списке
            $table->unsignedInteger('views_count')->default(0);

            $table->timestamps();

            // Индексы для частых запросов
            $table->index(['is_published', 'published_at']); // список опубликованных по дате
            $table->index('category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('articles');
    }
};