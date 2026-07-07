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
    }
}
