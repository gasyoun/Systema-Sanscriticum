<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Полоса публикации для story_posts (H3964, Phase 2).
 *
 * channel — текстовые посты канала, издаёт stories:publish-due (Bot API,
 * магнит-бот, Phase 1); lane=channel у всех существующих строк, поведение
 * Phase 1 байт-в-байт. persona — user-сториз персоны @rusamskrtam (СВОЙ
 * профиль, без админ-прав канала), издаёт stories:publish-story через
 * MadelineProto. Без разделения текстовые строки забирали бы ОБА издателя.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('story_posts', function (Blueprint $table) {
            $table->string('lane', 16)->default('channel')->after('kind'); // channel | persona
        });
    }

    public function down(): void
    {
        Schema::table('story_posts', function (Blueprint $table) {
            $table->dropColumn('lane');
        });
    }
};
