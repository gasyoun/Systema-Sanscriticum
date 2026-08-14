<?php

declare(strict_types=1);

namespace App\Services;

/**
 * H2443 — deterministic Hindi practice items from a lesson transcript.
 *
 * No LLM. No Sanskrit grammar rules. Tokens come from the transcript itself:
 * Devanagari, quoted Latin Hindi, or an explicit «X» — это Y / X значит Y pair.
 */
final class HindiTranscriptDrillExtractor
{
    public const MAX_ITEMS = 12;

    public const TYPE_CLOZE = 'cloze';

    public const TYPE_TRANSLATE = 'translate';

    public const TYPE_VOCAB_PICK = 'vocab_pick';

    /** @var list<string> */
    private const LATIN_STOP = [
        'the', 'and', 'for', 'you', 'this', 'that', 'with', 'from',
        'have', 'was', 'are', 'not', 'but', 'his', 'her', 'she',
        'they', 'them', 'our', 'your', 'what', 'when', 'who', 'how',
        // YouTube ru-orig / classroom English — not Hindi (measured on lesson 938).
        'zoom', 'google', 'telegram', 'whatsapp', 'youtube', 'iphone',
        'android', 'marina', 'pdf', 'chat', 'file', 'document', 'skype',
        'email', 'online', 'offline', 'hello', 'thanks', 'please',
        'okay', 'yes', 'okey', 'http', 'https', 'www',
    ];

    /**
     * Hindi classroom words as Whisper-ru writes them (Cyrillic).
     * Distinctive 4+ letter forms only — not нам/ту/хай/мат.
     *
     * @var list<string>
     */
    private const CYR_HINDI = [
        'намасте', 'намаскар', 'китаб', 'китааб', 'пани',
        'кайсе', 'кайса', 'кайси', 'деванагари', 'акшар',
        'шатрандж', 'сакахари', 'мансахари', 'ларка', 'ларки',
        'меронам', 'апка', 'апка', 'китаб',
    ];

    /**
     * @param  list<array{formatted_time?:string,start?:float,end?:float,text?:string,safe_text?:string}>  $sentences
     * @return list<array{
     *     id: string,
     *     type: string,
     *     prompt: string,
     *     sentence: string,
     *     answer: string,
     *     lemma: string,
     *     choices: list<string>|null,
     *     start: float
     * }>
     */
    public function extract(array $sentences): array
    {
        $items = [];
        $hindiPool = [];

        foreach ($sentences as $index => $sentence) {
            if (count($items) >= self::MAX_ITEMS) {
                break;
            }
            $text = trim((string) ($sentence['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $start = (float) ($sentence['start'] ?? $index);

            $pair = $this->glossPair($text);
            if ($pair !== null) {
                $items[] = $this->makeItem(
                    self::TYPE_TRANSLATE,
                    count($items),
                    'Как по-русски: '.$pair['hindi'],
                    $text,
                    $pair['gloss'],
                    $pair['hindi'],
                    null,
                    $start,
                );
                $hindiPool[] = $pair['hindi'];

                continue;
            }

            $target = $this->clozeTarget($text);
            if ($target === null) {
                continue;
            }

            $prompt = $this->blankOnce($text, $target);
            $items[] = $this->makeItem(
                self::TYPE_CLOZE,
                count($items),
                $prompt,
                $text,
                $target,
                $target,
                null,
                $start,
            );
            $hindiPool[] = $target;
        }

        $unique = array_values(array_unique($hindiPool));
        if (count($unique) >= 3 && count($items) < self::MAX_ITEMS) {
            $source = $items[0];
            $answer = $source['answer'];
            $distractors = array_values(array_filter(
                $unique,
                static fn (string $w): bool => mb_strtolower($w) !== mb_strtolower($answer),
            ));
            $choices = array_slice(array_merge([$answer], array_slice($distractors, 0, 2)), 0, 3);
            $items[] = $this->makeItem(
                self::TYPE_VOCAB_PICK,
                count($items),
                $source['type'] === self::TYPE_CLOZE ? $source['prompt'] : $this->blankOnce($source['sentence'], $answer),
                $source['sentence'],
                $answer,
                (string) ($source['lemma'] ?? $answer),
                $this->stableShuffle($choices, $source['id']),
                $source['start'],
            );
        }

        return array_slice($items, 0, self::MAX_ITEMS);
    }

    public static function answersMatch(string $expected, string $given): bool
    {
        return self::normalizeAnswer($expected) === self::normalizeAnswer($given);
    }

    public static function normalizeAnswer(string $value): string
    {
        $value = trim(mb_strtolower($value));
        $value = str_replace('ё', 'е', $value);
        $value = preg_replace('/[^\p{L}\p{N}\s]/u', '', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * @return array{hindi: string, gloss: string}|null
     */
    private function glossPair(string $text): ?array
    {
        if (preg_match('/[«"]([^»"]{2,40})[»"]\s*[—–-]\s*(?:это\s+)?(.+)$/u', $text, $m)) {
            return $this->pairFrom($m[1], $m[2]);
        }
        if (preg_match('/(\p{Devanagari}[\p{Devanagari}\p{M}\s]{0,40})\s+значит\s+(.+)$/u', $text, $m)) {
            return $this->pairFrom($m[1], $m[2]);
        }
        if (preg_match('/[«"]([^»"]{2,40})[»"]\s+значит\s+(.+)$/u', $text, $m)) {
            return $this->pairFrom($m[1], $m[2]);
        }

        return null;
    }

    /**
     * @return array{hindi: string, gloss: string}|null
     */
    private function pairFrom(string $hindi, string $gloss): ?array
    {
        $hindi = trim($hindi, " \t\n\r\0\x0B«»\"'");
        $gloss = trim($gloss, " \t\n\r\0\x0B«»\"'.!?,;:—–-");
        if ($hindi === '' || $gloss === '' || mb_strlen($gloss) > 40) {
            return null;
        }

        return ['hindi' => $hindi, 'gloss' => $gloss];
    }

    private function clozeTarget(string $text): ?string
    {
        $tokens = $this->tokens($text);
        $devanagari = [];
        $cyrHindi = [];
        $latin = [];
        foreach ($tokens as $token) {
            $bare = trim($token, "«»\"'");
            if ($bare === '') {
                continue;
            }
            if ($this->isDevanagari($bare) && mb_strlen($bare) >= 2) {
                $devanagari[] = $bare;
            } elseif ($this->isCyrillicHindi($bare)) {
                $cyrHindi[] = $bare;
            } elseif ($this->isLatinHindi($bare)) {
                $latin[] = $bare;
            }
        }

        if ($devanagari !== []) {
            return $this->longest($devanagari);
        }
        if ($cyrHindi !== []) {
            return $this->longest($cyrHindi);
        }
        if ($latin !== []) {
            return $this->longest($latin);
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function tokens(string $text): array
    {
        preg_match_all('/[\p{L}\p{M}]+/u', $text, $matches);

        return $matches[0] ?? [];
    }

    private function isDevanagari(string $token): bool
    {
        return (bool) preg_match('/\p{Devanagari}/u', $token);
    }

    private function isCyrillicHindi(string $token): bool
    {
        $lower = mb_strtolower($token);
        if (in_array($lower, self::CYR_HINDI, true)) {
            return true;
        }

        return str_starts_with($lower, 'намаст') && mb_strlen($lower) <= 10;
    }

    private function isLatinHindi(string $token): bool
    {
        if (mb_strlen($token) < 3) {
            return false;
        }
        if (preg_match('/\p{Cyrillic}/u', $token)) {
            return false;
        }
        if (! preg_match('/^[A-Za-zāīūṛṝḷḹṅñṭḍṇśṣḥṃēōĀĪŪṚṜḶḸṄÑṬḌṆŚṢḤṂ]+$/u', $token)) {
            return false;
        }

        return ! in_array(mb_strtolower($token), self::LATIN_STOP, true);
    }

    /**
     * @param  list<string>  $words
     */
    private function longest(array $words): string
    {
        usort($words, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return $words[0];
    }

    private function blankOnce(string $text, string $target): string
    {
        $quoted = preg_quote($target, '/');
        $replaced = preg_replace('/'.$quoted.'/u', '_____', $text, 1);

        return is_string($replaced) ? $replaced : $text;
    }

    /**
     * @param  list<string>|null  $choices
     * @return array{
     *     id: string,
     *     type: string,
     *     prompt: string,
     *     sentence: string,
     *     answer: string,
     *     lemma: string,
     *     choices: list<string>|null,
     *     start: float
     * }
     */
    private function makeItem(
        string $type,
        int $index,
        string $prompt,
        string $sentence,
        string $answer,
        string $lemma,
        ?array $choices,
        float $start,
    ): array {
        $hash = substr(sha1($type.'|'.$start.'|'.$answer.'|'.$prompt), 0, 8);

        return [
            'id' => 'htd-'.$type.'-'.$index.'-'.$hash,
            'type' => $type,
            'prompt' => $prompt,
            'sentence' => $sentence,
            'answer' => $answer,
            'lemma' => $lemma,
            'choices' => $choices,
            'start' => $start,
        ];
    }

    /**
     * @param  list<string>  $choices
     * @return list<string>
     */
    private function stableShuffle(array $choices, string $seed): array
    {
        $choices = array_values($choices);
        $offset = hexdec(substr(sha1($seed), 0, 8)) % max(1, count($choices));
        if ($offset === 0) {
            return $choices;
        }

        return array_values(array_merge(
            array_slice($choices, $offset),
            array_slice($choices, 0, $offset),
        ));
    }
}
