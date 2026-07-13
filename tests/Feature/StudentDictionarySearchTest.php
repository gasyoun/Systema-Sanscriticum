<?php

namespace Tests\Feature;

use App\Livewire\StudentDictionary;
use App\Models\Dictionary;
use App\Models\DictionaryWord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Covers App\Livewire\StudentDictionary search after the FULLTEXT refactor.
 *
 * NOTE: the test DB is SQLite (phpunit.xml), which has no FULLTEXT support, so
 * these tests exercise the LIKE fallback branch of applySearch(). The MySQL
 * MATCH ... AGAINST branch (mb_strlen >= 3 && driver === 'mysql') is NOT
 * reachable here and must be validated on staging against real MySQL — see
 * handoff H859.
 */
class StudentDictionarySearchTest extends TestCase
{
    use RefreshDatabase;

    private ?Dictionary $dictionary = null;

    private function word(array $attributes): DictionaryWord
    {
        $this->dictionary ??= Dictionary::create(['name' => 'Тестовый словарь', 'is_active' => true]);

        return DictionaryWord::create(array_merge([
            'dictionary_id' => $this->dictionary->id,
            'devanagari' => 'धर्म',
            'iast' => 'dharma',
            'cyrillic' => 'дхарма',
            'translation' => 'закон, долг, порядок',
        ], $attributes));
    }

    public function test_search_matches_by_iast_and_excludes_others(): void
    {
        $this->word(['iast' => 'dharma', 'translation' => 'закон']);
        $this->word(['iast' => 'artha', 'devanagari' => 'अर्थ', 'cyrillic' => 'артха', 'translation' => 'цель, польза']);

        Livewire::test(StudentDictionary::class)
            ->set('search', 'dharma')
            ->assertSee('закон')
            ->assertDontSee('цель, польза');
    }

    public function test_short_query_still_matches_via_substring_fallback(): void
    {
        // Two-character query — below MySQL's default min token size; the LIKE
        // fallback must still find it as a substring.
        $this->word(['iast' => 'dharma', 'translation' => 'закон']);

        Livewire::test(StudentDictionary::class)
            ->set('search', 'ha')
            ->assertSee('закон');
    }

    public function test_search_matches_by_translation_substring(): void
    {
        $this->word(['iast' => 'dharma', 'translation' => 'закон, долг, порядок']);

        Livewire::test(StudentDictionary::class)
            ->set('search', 'долг')
            ->assertSee('dharma');
    }

    public function test_empty_search_returns_all_words(): void
    {
        $this->word(['iast' => 'dharma', 'translation' => 'закон']);
        $this->word(['iast' => 'artha', 'devanagari' => 'अर्थ', 'cyrillic' => 'артха', 'translation' => 'цель']);

        Livewire::test(StudentDictionary::class)
            ->set('search', '')
            ->assertSee('dharma')
            ->assertSee('artha');
    }
}
