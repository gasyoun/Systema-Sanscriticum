<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\GrammarExercise;
use App\Models\SrsCard;
use App\Models\SrsDeck;
use App\Models\SrsNoteType;
use App\Models\User;

/**
 * Private per-user Grammar Lab deck. Ownership (not a shared system deck)
 * is the only entitlement boundary on /dvaram/koloda — same reason as
 * StartChteniyaSrsDeck. Cards are keyed by exercise_id; FSRS state lives
 * only in srs_review_states via ReviewService.
 */
final class GrammarLabSrsDeck
{
    public const DECK_SLUG = 'grammar-lab';

    public const NOTE_TYPE_KEY = 'grammar_lab_exercise';

    public const FIELDS = [
        'exercise_id',
        'topic_id',
        'prompt_ru',
        'answer',
        'answer_normalized',
        'content_hash',
    ];

    public static function noteType(): SrsNoteType
    {
        return SrsNoteType::firstOrCreate(
            ['key' => self::NOTE_TYPE_KEY],
            [
                'name' => 'Лаборатория грамматики — упражнение',
                'language' => 'sa',
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
                'name' => 'Лаборатория грамматики',
                'language' => 'sa',
                'visibility' => 'private',
                'description' => 'H2494: published Grammar Lab exercises projected into existing FSRS.',
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    public static function cardKey(array $fields): string
    {
        return (string) ($fields['exercise_id'] ?? '');
    }

    public static function addExercise(User $user, GrammarExercise $exercise): SrsCard
    {
        $deck = self::deckFor($user);
        $payload = is_array($exercise->payload) ? $exercise->payload : [];
        $fields = [
            'exercise_id' => $exercise->exercise_id,
            'topic_id' => $exercise->topic_id,
            'prompt_ru' => (string) ($payload['prompt_ru'] ?? ''),
            'answer' => (string) ($payload['answer'] ?? ''),
            'answer_normalized' => (string) ($payload['answer_normalized'] ?? $payload['answer'] ?? ''),
            'content_hash' => $exercise->content_hash,
        ];

        foreach (SrsCard::query()->where('deck_id', $deck->id)->get() as $card) {
            if (self::cardKey($card->fields ?? []) === $exercise->exercise_id) {
                $card->fields = $fields;
                $card->save();

                return $card;
            }
        }

        return SrsCard::query()->create([
            'deck_id' => $deck->id,
            'fields' => $fields,
            'direction' => 'front_back',
        ]);
    }
}
