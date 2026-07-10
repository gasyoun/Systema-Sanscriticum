<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\DictionaryWord;
use App\Models\SrsCard;
use App\Models\SrsDeck;
use App\Models\SrsNoteType;
use Illuminate\Database\Seeder;

/**
 * Сеет одну СИСТЕМНУЮ санскритскую колоду из словаря (H211, Wave 1).
 * Идемпотентно: тип карточки, колода и карточки берутся через firstOrCreate,
 * повторный запуск не дублирует. Безопасно при пустой таблице словаря
 * (создаст колоду без карточек).
 */
class SrsSanskritDeckSeeder extends Seeder
{
    public function run(): void
    {
        $noteType = SrsNoteType::firstOrCreate(
            ['key' => 'sanskrit_basic'],
            [
                'name' => 'Санскрит — базовая',
                'language' => 'sa',
                'fields' => ['devanagari', 'iast', 'cyrillic', 'translation'],
            ],
        );

        $deck = SrsDeck::firstOrCreate(
            ['slug' => 'sanskrit-core', 'user_id' => null],
            [
                'note_type_id' => $noteType->id,
                'name' => 'Санскрит — ядро словаря',
                'language' => 'sa',
                'visibility' => 'system',
                'description' => 'Системная колода: слова из словаря личного кабинета.',
            ],
        );

        // Годятся карточки со значением и хотя бы одной формой написания.
        DictionaryWord::query()
            ->whereNotNull('translation')
            ->where('translation', '!=', '')
            ->where(function ($q) {
                $q->whereNotNull('devanagari')->where('devanagari', '!=', '')
                    ->orWhere(function ($q2) {
                        $q2->whereNotNull('iast')->where('iast', '!=', '');
                    });
            })
            ->chunkById(500, function ($words) use ($deck) {
                foreach ($words as $word) {
                    SrsCard::firstOrCreate(
                        ['deck_id' => $deck->id, 'source_word_id' => $word->id],
                        [
                            'direction' => 'front_back',
                            'fields' => [
                                'devanagari' => (string) $word->devanagari,
                                'iast' => (string) $word->iast,
                                'cyrillic' => (string) $word->cyrillic,
                                'translation' => (string) $word->translation,
                            ],
                        ],
                    );
                }
            });

        $this->seedCyrillicOnlyDeck();
    }

    /**
     * H447 — Saraswati trainer suite Phase 1. The 28-Aug cohort is true-zero:
     * days 1–2 are Cyrillic-only, they cannot read Devanagari yet. Every card
     * face uses ONLY `cyrillic` + `translation` — the `devanagari` key is
     * omitted from `fields` entirely (not an empty string) so no script
     * trainer can accidentally surface it. Words without a Cyrillic
     * transcription are skipped, not backfilled —
     * see H447 hazard #2: there is no sanctioned IAST→Cyrillic path here and
     * this deck must not invent one.
     */
    private function seedCyrillicOnlyDeck(): void
    {
        $noteType = SrsNoteType::firstOrCreate(
            ['key' => 'sanskrit_cyrillic_only'],
            [
                'name' => 'Санскрит — только кириллица',
                'language' => 'sa',
                'fields' => ['cyrillic', 'translation'],
            ],
        );

        $deck = SrsDeck::firstOrCreate(
            ['slug' => 'sanskrit-cyrillic-only', 'user_id' => null],
            [
                'note_type_id' => $noteType->id,
                'name' => 'Санскрит — кириллица (день 1–2)',
                'language' => 'sa',
                'visibility' => 'system',
                'description' => 'Системная колода без деванагари — для тех, кто ещё не читает письмо.',
            ],
        );

        DictionaryWord::query()
            ->whereNotNull('translation')
            ->where('translation', '!=', '')
            ->whereNotNull('cyrillic')
            ->where('cyrillic', '!=', '')
            ->chunkById(500, function ($words) use ($deck) {
                foreach ($words as $word) {
                    SrsCard::firstOrCreate(
                        ['deck_id' => $deck->id, 'source_word_id' => $word->id],
                        [
                            'direction' => 'front_back',
                            'fields' => [
                                'cyrillic' => (string) $word->cyrillic,
                                'translation' => (string) $word->translation,
                            ],
                        ],
                    );
                }
            });
    }
}
