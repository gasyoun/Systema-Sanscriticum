<?php

declare(strict_types=1);

namespace App\Services\Support\StudentAgent\Tools;

use App\Livewire\StudentDictionary;
use App\Models\DictionaryWord;
use App\Models\User;

/**
 * Job 2/3 (H3231): dictionary lookup. Pure DB search — same FULLTEXT/LIKE
 * strategy as {@see StudentDictionary}, no LLM call at all, so
 * it is unaffected by the student_agent LLM budget. Always non-irreversible.
 */
final class DictionaryLookupTool implements StudentAgentTool
{
    private const LIMIT = 10;

    public function name(): string
    {
        return 'dictionary_lookup';
    }

    public function isIrreversible(): bool
    {
        return false;
    }

    public function run(User $user, array $params): array
    {
        $term = trim((string) ($params['query'] ?? ''));

        if ($term === '') {
            return ['ok' => false, 'reason' => 'empty_query'];
        }

        $words = DictionaryWord::query()->with('dictionary');
        $useFulltext = $words->getConnection()->getDriverName() === 'mysql'
            && mb_strlen($term) >= 3;

        if ($useFulltext) {
            $boolean = $this->booleanFulltextTerm($term);
            if ($boolean !== '') {
                $words->whereFullText(
                    ['devanagari', 'iast', 'cyrillic', 'translation'],
                    $boolean,
                    ['mode' => 'boolean'],
                );
            }
        }

        if (! $useFulltext || $this->booleanFulltextTerm($term) === '') {
            $words->where(function ($query) use ($term) {
                $query->where('devanagari', 'like', '%'.$term.'%')
                    ->orWhere('iast', 'like', '%'.$term.'%')
                    ->orWhere('cyrillic', 'like', '%'.$term.'%')
                    ->orWhere('translation', 'like', '%'.$term.'%');
            });
        }

        $hits = $words->limit(self::LIMIT)->get()->map(fn (DictionaryWord $w) => [
            'devanagari' => $w->devanagari,
            'iast' => $w->iast,
            'cyrillic' => $w->cyrillic,
            'translation' => $w->translation,
            'dictionary' => $w->dictionary?->name,
        ])->all();

        return ['ok' => true, 'data' => ['query' => $term, 'hits' => $hits]];
    }

    private function booleanFulltextTerm(string $search): string
    {
        $clean = preg_replace('/[+\-><()~*"@]+/u', ' ', $search);
        $tokens = preg_split('/\s+/u', trim((string) $clean), -1, PREG_SPLIT_NO_EMPTY);

        if ($tokens === []) {
            return '';
        }

        return implode(' ', array_map(static fn ($token) => '+'.$token.'*', $tokens));
    }
}
