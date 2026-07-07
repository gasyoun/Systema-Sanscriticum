<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SRS (Wave 1, H211) — карточки колоды. `fields` — снимок значений полей
 * (devanagari/iast/cyrillic/translation) на момент создания. `direction` —
 * какой стороной показывать (лицо→оборот по умолчанию). `source_word_id` —
 * ссылка на исходное слово словаря для сид-карточек (nullable).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('srs_cards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('deck_id')->constrained('srs_decks')->cascadeOnDelete();
            $table->json('fields');
            $table->enum('direction', ['front_back', 'back_front'])->default('front_back');
            $table->foreignId('source_word_id')->nullable()->constrained('dictionary_words')->nullOnDelete();
            $table->timestamps();

            $table->index('deck_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('srs_cards');
    }
};
