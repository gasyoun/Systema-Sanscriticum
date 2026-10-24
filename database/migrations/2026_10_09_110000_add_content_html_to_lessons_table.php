<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Богатое тело урока (мини-курсы): HTML с вёрсткой этапа — шапка, картинки,
 * карточки практики, акшары, ссылки на видео. Заполняется поверх topic
 * (текстовый фолбэк остаётся); санитизируется при записи, как описание курса.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->text('content_html')->nullable()->after('topic');
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->dropColumn('content_html');
        });
    }
};
