<?php

namespace App\Livewire;

use App\Models\Dictionary;
use App\Models\DictionaryWord;
use Livewire\Component;
use Livewire\WithPagination;

class StudentDictionary extends Component
{
    use WithPagination;

    public $search = '';

    public $dictionary_id = 'all';

    // Обновляем пагинацию при поиске
    public function updatingSearch()
    {
        $this->resetPage();
    }

    public function updatingDictionaryId()
    {
        $this->resetPage();
    }

    public function render()
    {
        $dictionaries = Dictionary::where('is_active', true)->get();

        // Сразу подгружаем словарь, чтобы не было лишних запросов к базе (N+1 проблема)
        $words = DictionaryWord::with('dictionary');

        if ($this->dictionary_id !== 'all') {
            $words->where('dictionary_id', $this->dictionary_id);
        }

        $this->applySearch($words);

        return view('livewire.student-dictionary', [
            'dictionaries' => $dictionaries,
            'words' => $words->paginate(20),
        ]);
    }

    /**
     * Фильтр по строке поиска.
     *
     * На MySQL с достаточно длинным запросом используем составной FULLTEXT-индекс
     * (`dictionary_words_fulltext`, миграция 2026_07_13_120000) через MATCH ... AGAINST
     * в BOOLEAN MODE — это использует индекс вместо полного скана таблицы, который
     * давал ведущий wildcard `LIKE '%term%'`.
     *
     * LIKE-фолбэк оставлен для:
     *   - не-MySQL драйверов (SQLite в тестах FULLTEXT не поддерживает);
     *   - коротких запросов — MySQL FULLTEXT игнорирует токены короче
     *     innodb_ft_min_token_size (по умолчанию 3), а для санскритских корней
     *     двухсимвольные запросы обычны, и MATCH молча вернул бы пусто.
     * Семантика при этом отличается: FULLTEXT ищет по НАЧАЛУ слова (префикс),
     * LIKE — по подстроке в любом месте.
     */
    protected function applySearch($words): void
    {
        $term = trim($this->search);

        if ($term === '') {
            return;
        }

        $useFulltext = $words->getConnection()->getDriverName() === 'mysql'
            && mb_strlen($term) >= 3;

        if ($useFulltext) {
            $booleanTerm = $this->booleanFulltextTerm($term);

            if ($booleanTerm !== '') {
                $words->whereFullText(
                    ['devanagari', 'iast', 'cyrillic', 'translation'],
                    $booleanTerm,
                    ['mode' => 'boolean'],
                );

                return;
            }
        }

        $words->where(function ($query) use ($term) {
            $query->where('devanagari', 'like', '%'.$term.'%')
                ->orWhere('iast', 'like', '%'.$term.'%')
                ->orWhere('cyrillic', 'like', '%'.$term.'%')
                ->orWhere('translation', 'like', '%'.$term.'%');
        });
    }

    /**
     * Собирает BOOLEAN-MODE выражение для MATCH ... AGAINST: нейтрализует
     * операторы boolean-режима, которые пользователь мог ввести, и требует
     * каждый токен как префикс (+token*). Возвращает '' если после чистки не
     * осталось токенов (тогда вызывающий код падает в LIKE-фолбэк).
     */
    protected function booleanFulltextTerm(string $search): string
    {
        $clean = preg_replace('/[+\-><()~*"@]+/u', ' ', $search);
        $tokens = preg_split('/\s+/u', trim((string) $clean), -1, PREG_SPLIT_NO_EMPTY);

        if ($tokens === []) {
            return '';
        }

        return implode(' ', array_map(static fn ($token) => '+'.$token.'*', $tokens));
    }
}
