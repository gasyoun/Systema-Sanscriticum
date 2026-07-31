<?php

declare(strict_types=1);

namespace App\Services\Srs;

use App\Models\SrsCard;

/**
 * Resolve prompt / answer strings from heterogeneous card field maps
 * (Memrise import, Dictionary seeder, Anki, Kochergina, game onboarding).
 */
final class CardFace
{
    /** @var list<string> */
    private const PROMPT_KEYS = ['devanagari', 'iast', 'cyrillic', 'front', 'lemma'];

    /** @var list<string> */
    private const ANSWER_KEYS = [
        'translation',
        'translation_ru',
        'translation_en',
        'meaning',
        'back',
        'gloss',
    ];

    public static function prompt(SrsCard $card): string
    {
        return self::firstNonEmpty($card->fields ?? [], self::PROMPT_KEYS);
    }

    public static function answer(SrsCard $card): string
    {
        return self::firstNonEmpty($card->fields ?? [], self::ANSWER_KEYS);
    }

    /**
     * All acceptable typing answers: primary answer + alt_answers (comma/semicolon/pipe).
     *
     * @return list<string>
     */
    public static function acceptableAnswers(SrsCard $card): array
    {
        $fields = $card->fields ?? [];
        $out = [];
        $primary = self::answer($card);
        if ($primary !== '') {
            $out[] = $primary;
        }

        $alts = (string) ($fields['alt_answers'] ?? $fields['alts'] ?? '');
        if ($alts !== '') {
            foreach (preg_split('/[,;|]/u', $alts) ?: [] as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $out[] = $part;
                }
            }
        }

        // Also accept reverse-script prompts as typed answers when they exist.
        foreach (['devanagari', 'iast', 'cyrillic'] as $key) {
            $v = trim((string) ($fields[$key] ?? ''));
            if ($v !== '') {
                $out[] = $v;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  list<string>  $keys
     */
    private static function firstNonEmpty(array $fields, array $keys): string
    {
        foreach ($keys as $key) {
            $v = trim((string) ($fields[$key] ?? ''));
            if ($v !== '') {
                return $v;
            }
        }

        return '';
    }
}
