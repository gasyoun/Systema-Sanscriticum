<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H7966 (трек А, MG 10-11-2026) — таксономия колод SRS:
 *
 * - `topic` / `difficulty` / `size_tier` — оси таксономии (тема, сложность,
 *   размер); заполняются офлайн-проходом (SrsTaxonomySeeder) и уточняются
 *   учителем в Filament до публикации (гидридная схема Q9).
 * - `tags` — свободные метки (json), расширение без миграций.
 * - `parent_deck_id` — сегментация мега-деков (Q18): подколоды
 *   топ-500 / 500-2000 / остальное ссылаются на исходный мега-дек.
 *
 * Все колонки nullable — старые колоды живут без таксономии, пока учитель
 * не утвердит значения. Новых `visibility` не заводим (дефолт 2 грилла).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('srs_decks', function (Blueprint $table): void {
            $table->string('topic', 64)->nullable()->after('description');
            $table->string('difficulty', 32)->nullable()->after('topic');
            $table->string('size_tier', 16)->nullable()->after('difficulty');
            $table->json('tags')->nullable()->after('size_tier');
            $table->foreignId('parent_deck_id')
                ->nullable()
                ->after('tags')
                ->constrained('srs_decks')
                ->nullOnDelete();

            $table->index('topic');
            $table->index('size_tier');
            $table->index('difficulty');
        });
    }

    public function down(): void
    {
        Schema::table('srs_decks', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('parent_deck_id');
            $table->dropIndex(['topic']);
            $table->dropIndex(['size_tier']);
            $table->dropIndex(['difficulty']);
            $table->dropColumn(['tags', 'size_tier', 'difficulty', 'topic']);
        });
    }
};
