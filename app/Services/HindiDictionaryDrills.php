<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;

/**
 * H3206 — practice items from Kostina's module dictionaries.
 *
 * Flag default OFF. Access = owns ≥1 Hindi lesson (H2441). No LLM.
 */
final class HindiDictionaryDrills
{
    public const TRANSLATE_CAP = 24;

    public const REVERSE_CAP = 8;

    public function __construct(
        private readonly HindiKostinaDictionary $dictionary,
        private readonly HindiProgrammePlaylist $playlist,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('features.hindi_dictionary_drills', false);
    }

    public function userCanAccess(User $user): bool
    {
        return $this->playlist->itemsFor($user)->isNotEmpty();
    }

    /**
     * @return list<array{
     *     id: string,
     *     type: string,
     *     prompt: string,
     *     answer: string,
     *     lemma: string,
     *     choices: list<string>|null,
     *     module: string
     * }>
     */
    public function itemsFor(string $module): array
    {
        $entries = $this->dictionary->entriesFor($module);
        if ($entries === []) {
            return [];
        }
        $items = [];
        $slice = array_slice($entries, 0, self::TRANSLATE_CAP);
        foreach ($slice as $i => $entry) {
            $items[] = [
                'id' => $entry['id'].'-tr',
                'type' => HindiTranscriptDrillExtractor::TYPE_TRANSLATE,
                'prompt' => 'Как по-русски: '.$entry['hindi'],
                'answer' => $entry['ru'],
                'lemma' => $entry['hindi'],
                'choices' => null,
                'module' => $module,
            ];
            if ($i < self::REVERSE_CAP) {
                $items[] = [
                    'id' => $entry['id'].'-rv',
                    'type' => HindiTranscriptDrillExtractor::TYPE_TRANSLATE,
                    'prompt' => 'Как по-хинди: '.$this->shortGloss($entry['ru']),
                    'answer' => $entry['hindi'],
                    'lemma' => $entry['hindi'],
                    'choices' => null,
                    'module' => $module,
                ];
            }
        }
        if (count($slice) >= 3) {
            $answer = $slice[0]['ru'];
            $choices = [
                $slice[0]['ru'],
                $slice[1]['ru'],
                $slice[2]['ru'],
            ];
            $items[] = [
                'id' => $slice[0]['id'].'-pick',
                'type' => HindiTranscriptDrillExtractor::TYPE_VOCAB_PICK,
                'prompt' => 'Как по-русски: '.$slice[0]['hindi'],
                'answer' => $answer,
                'lemma' => $slice[0]['hindi'],
                'choices' => $this->stableShuffle($choices, $slice[0]['id']),
                'module' => $module,
            ];
        }

        return $items;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findItem(string $module, string $itemId): ?array
    {
        foreach ($this->itemsFor($module) as $item) {
            if ($item['id'] === $itemId) {
                return $item;
            }
        }

        return null;
    }

    public static function answersMatch(string $expected, string $given): bool
    {
        if (HindiTranscriptDrillExtractor::answersMatch($expected, $given)) {
            return true;
        }
        $stripped = trim((string) preg_replace('/\([^)]*\)/u', '', $expected));
        if ($stripped !== '' && $stripped !== $expected
            && HindiTranscriptDrillExtractor::answersMatch($stripped, $given)) {
            return true;
        }
        $parts = preg_split('/[,;\/]|—|–/u', $expected) ?: [];
        foreach ($parts as $part) {
            $part = trim((string) preg_replace('/\([^)]*\)/u', '', $part));
            if ($part !== '' && HindiTranscriptDrillExtractor::answersMatch($part, $given)) {
                return true;
            }
        }

        return false;
    }

    public function probe(): array
    {
        $modules = [];
        $itemTotal = 0;
        foreach ($this->dictionary->modules() as $mod) {
            $n = count($this->itemsFor($mod['id']));
            $itemTotal += $n;
            $modules[] = [
                'module' => $mod['id'],
                'label' => $mod['label'],
                'entries' => $mod['count'],
                'items' => $n,
            ];
        }

        return [
            'flag' => $this->enabled(),
            'path' => $this->dictionary->storePath(),
            'entry_total' => array_sum(array_column($modules, 'entries')),
            'item_total' => $itemTotal,
            'modules' => $modules,
        ];
    }

    private function shortGloss(string $ru): string
    {
        $parts = preg_split('/[,;]/u', $ru) ?: [$ru];
        $first = trim((string) $parts[0]);

        return $first !== '' ? $first : $ru;
    }

    /**
     * @param  list<string>  $choices
     * @return list<string>
     */
    private function stableShuffle(array $choices, string $seed): array
    {
        $out = $choices;
        $n = count($out);
        for ($i = $n - 1; $i > 0; $i--) {
            $j = hexdec(substr(md5($seed.'-'.$i), 0, 8)) % ($i + 1);
            [$out[$i], $out[$j]] = [$out[$j], $out[$i]];
        }

        return array_values($out);
    }
}
