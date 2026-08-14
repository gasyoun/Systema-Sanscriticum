<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\SrsCard;
use App\Models\SrsDeck;
use App\Models\SrsNoteType;
use App\Models\User;
use App\Services\HindiTranscriptDrillExtractor;

/**
 * H2445 — the ONE definition of the private «Мой хинди» SRS deck.
 *
 * Same ownership fence as StartChteniyaSrsDeck: the deck is `private` with
 * `user_id` set. SrsController lists system/public decks to every logged-in
 * user, so ownership is the only thing that keeps a student's Hindi lemmas
 * off someone else's /dvaram/koloda hub. Do not promote this to a shared deck
 * and do not write into Hindi Core (`hindi-core`).
 *
 * Cards are keyed by the normalised Hindi lemma. Two drill items that teach
 * the same word (translate + vocab_pick of kitāb) collapse to one card.
 * Card text is always taken from a server-side drill item — the client posts
 * an item id or a lesson id, never the lemma itself.
 *
 * Adding a card is gated by `features.hindi_my_srs_deck` and Hindi lesson
 * access. It is deliberately NOT gated by `config('srs.enabled')` (H2106
 * fence): a collected card waits for the existing koloda review UI.
 */
final class HindiMySrsDeck
{
    public const DECK_SLUG = 'my-hindi';

    public const NOTE_TYPE_KEY = 'hindi_my_deck';

    public const TRANSLATE_PREFIX = 'Как по-русски: ';

    /** @var list<string> */
    public const FIELDS = [
        'lemma',
        'gloss',
        'prompt',
        'type',
        'lesson_id',
        'item_id',
        'course_slug',
    ];

    public static function enabled(): bool
    {
        return (bool) config('features.hindi_my_srs_deck', false);
    }

    public static function noteType(): SrsNoteType
    {
        return SrsNoteType::firstOrCreate(
            ['key' => self::NOTE_TYPE_KEY],
            [
                'name' => 'Мой хинди — леммы программы',
                'language' => 'hi',
                'fields' => self::FIELDS,
            ],
        );
    }

    public static function deckFor(User $user): SrsDeck
    {
        return SrsDeck::firstOrCreate(
            ['user_id' => $user->id, 'slug' => self::DECK_SLUG],
            [
                'note_type_id' => self::noteType()->id,
                'name' => 'Мой хинди',
                'language' => 'hi',
                'visibility' => 'private',
                'description' => 'H2445: lemmas from Hindi programme drills (transcript items). Private per student.',
            ],
        );
    }

    /**
     * Hindi headword for a drill item. Translate items store the Russian gloss
     * in `answer`; the Hindi word lives in the prompt after the fixed prefix.
     *
     * @param  array<string, mixed>  $item
     */
    public static function lemmaFromItem(array $item): string
    {
        $explicit = trim((string) ($item['lemma'] ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }

        $prompt = trim((string) ($item['prompt'] ?? ''));
        if (str_starts_with($prompt, self::TRANSLATE_PREFIX)) {
            return trim(mb_substr($prompt, mb_strlen(self::TRANSLATE_PREFIX)));
        }

        return trim((string) ($item['answer'] ?? ''));
    }

    /**
     * Review-face gloss: Russian when the item has one, otherwise the source
     * sentence. Never invents a translation.
     *
     * @param  array<string, mixed>  $item
     */
    public static function glossFromItem(array $item): string
    {
        $lemma = self::lemmaFromItem($item);
        $answer = trim((string) ($item['answer'] ?? ''));
        if ($answer !== ''
            && HindiTranscriptDrillExtractor::normalizeAnswer($answer)
                !== HindiTranscriptDrillExtractor::normalizeAnswer($lemma)
        ) {
            return $answer;
        }

        $sentence = trim((string) ($item['sentence'] ?? ''));

        return $sentence !== '' ? $sentence : trim((string) ($item['prompt'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    public static function cardKey(array $fields): string
    {
        return HindiTranscriptDrillExtractor::normalizeAnswer((string) ($fields['lemma'] ?? ''));
    }

    /**
     * @return array<string, true>
     */
    public static function existingKeys(SrsDeck $deck): array
    {
        $keys = [];
        foreach (SrsCard::query()->where('deck_id', $deck->id)->get(['fields']) as $card) {
            $key = self::cardKey($card->fields ?? []);
            if ($key !== '') {
                $keys[$key] = true;
            }
        }

        return $keys;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, string>
     */
    public static function fieldsFromItem(Lesson $lesson, Course $course, array $item): array
    {
        $fields = [
            'lemma' => self::lemmaFromItem($item),
            'gloss' => self::glossFromItem($item),
            'prompt' => (string) ($item['prompt'] ?? ''),
            'type' => (string) ($item['type'] ?? ''),
            'lesson_id' => (string) $lesson->id,
            'item_id' => (string) ($item['id'] ?? ''),
            'course_slug' => (string) $course->slug,
        ];

        $normalized = [];
        foreach (self::FIELDS as $field) {
            $normalized[$field] = (string) ($fields[$field] ?? '');
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return 'added'|'duplicate'|'no_lemma'
     */
    public static function addItem(User $user, Lesson $lesson, Course $course, array $item): string
    {
        $fields = self::fieldsFromItem($lesson, $course, $item);
        if ($fields['lemma'] === '') {
            return 'no_lemma';
        }

        $deck = self::deckFor($user);
        if (isset(self::existingKeys($deck)[self::cardKey($fields)])) {
            return 'duplicate';
        }

        SrsCard::query()->create([
            'deck_id' => $deck->id,
            'direction' => 'front_back',
            'source_word_id' => null,
            'fields' => $fields,
        ]);

        return 'added';
    }
}
