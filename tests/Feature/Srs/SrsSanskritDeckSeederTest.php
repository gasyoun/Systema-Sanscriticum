<?php

declare(strict_types=1);

namespace Tests\Feature\Srs;

use App\Models\Dictionary;
use App\Models\DictionaryWord;
use App\Models\SrsDeck;
use Database\Seeders\SrsSanskritDeckSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H447 — the true-zero 28-Aug cohort needs a deck whose card face is
 * Cyrillic + translation ONLY, with no Devanagari to sneak in.
 */
class SrsSanskritDeckSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_a_cyrillic_only_deck_with_no_devanagari_face(): void
    {
        $dict = Dictionary::create(['name' => 'Test dict', 'is_active' => true]);

        DictionaryWord::create([
            'dictionary_id' => $dict->id,
            'devanagari' => 'सत्य',
            'iast' => 'satya',
            'cyrillic' => 'сатья',
            'translation' => 'истина',
        ]);

        // Word with a Cyrillic form only (no devanagari) must still be picked up.
        DictionaryWord::create([
            'dictionary_id' => $dict->id,
            'cyrillic' => 'дхарма',
            'translation' => 'закон, долг',
        ]);

        // Word with no Cyrillic form must be skipped by the Cyrillic-only deck.
        DictionaryWord::create([
            'dictionary_id' => $dict->id,
            'devanagari' => 'योग',
            'iast' => 'yoga',
            'translation' => 'единение',
        ]);

        (new SrsSanskritDeckSeeder)->run();

        $deck = SrsDeck::where('slug', 'sanskrit-cyrillic-only')->firstOrFail();
        $this->assertSame('system', $deck->visibility);

        $cards = $deck->cards()->get();
        $this->assertCount(2, $cards);

        foreach ($cards as $card) {
            $this->assertArrayNotHasKey('devanagari', $card->fields);
            $this->assertArrayHasKey('cyrillic', $card->fields);
            $this->assertArrayHasKey('translation', $card->fields);
            $this->assertNotSame('', $card->fields['cyrillic']);
        }
    }
}
