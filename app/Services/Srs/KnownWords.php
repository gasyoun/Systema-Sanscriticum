<?php

declare(strict_types=1);

namespace App\Services\Srs;

use App\Models\SrsCard;
use App\Models\SrsDeck;
use App\Models\SrsReviewState;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * H8599 — трек Б SRS-программы: «не повторять уже выученное».
 *
 * Две половины одной механики, оба уровня работают по ОДНОМУ ключу формы
 * (лемма + тип заметки):
 *  - импорт: системный импорт не создаёт вторую карточку той же формы и
 *    сообщает счётчик `skipped`;
 *  - очередь: `ReviewService::queueFor` не выдаёт карточку, у которой есть
 *    зрелый близнец (FSRS-интервал >= 21 дня) у того же пользователя.
 *
 * Всё — no-op при выключенном `srs.known_words.enabled` (дефолт OFF), поэтому
 * при флаге OFF путь исполнения байт-в-байт совпадает с доказным поведением.
 * Никакого runtime-LLM; личная персонализация — только для сохранённых
 * пользователей (гости идут через `guestQueue`, 152-ФЗ / R3/Q15).
 */
final class KnownWords
{
    /** Anki-конвенция, решение R2/Q10 grill 10-11-2026. */
    public const DEFAULT_MATURE_DAYS = 21;

    public static function enabled(): bool
    {
        return (bool) config('srs.known_words.enabled', false);
    }

    public static function matureDays(): int
    {
        $days = (int) config('srs.known_words.mature_days', self::DEFAULT_MATURE_DAYS);

        return $days > 0 ? $days : self::DEFAULT_MATURE_DAYS;
    }

    /**
     * FSRS-интервал сохранённого состояния: число дней между последним
     * повторением и сроком следующего. Не `reps` и не `lapses` (R2/Q10).
     */
    public static function intervalDays(SrsReviewState $state): ?int
    {
        if ($state->last_review === null || $state->due === null) {
            return null;
        }

        // Carbon 3 returns a SIGNED difference; due is later than last_review,
        // so take abs() rather than clamping negatives to zero.
        return (int) abs($state->due->diffInDays($state->last_review));
    }

    public static function isMature(SrsReviewState $state): bool
    {
        $interval = self::intervalDays($state);

        return $interval !== null && $interval >= self::matureDays();
    }

    /**
     * Убирает из очереди кандидатов, у которых есть зрелый близнец той же
     * формы в ЛЮБОЙ колоде того же пользователя (кроме самой карточки).
     * Без user_id (гость) и при флаге OFF фильтра нет.
     */
    public function filterQueue(User $user, Collection $cards): Collection
    {
        if (! self::enabled() || ! $user->exists || $cards->isEmpty()) {
            return $cards;
        }

        $mature = $this->matureShapeMap($user);
        if ($mature === []) {
            return $cards;
        }

        $decks = SrsDeck::query()
            ->whereIn('id', $cards->pluck('deck_id')->filter()->unique()->all())
            ->with('noteType')
            ->get()
            ->keyBy('id');

        return $cards->reject(function (SrsCard $card) use ($mature, $decks): bool {
            $shape = self::shapeKey($decks->get($card->deck_id), $card->source_word_id, $card->fields);
            if ($shape === null) {
                return false;
            }
            foreach ($mature[$shape] ?? [] as $twinId) {
                if ($twinId !== $card->id) {
                    return true;
                }
            }

            return false;
        })->values();
    }

    /**
     * @return array<string, array<int, int>> ключ формы => id зрелых карточек
     */
    private function matureShapeMap(User $user): array
    {
        $map = [];

        SrsReviewState::query()
            ->where('user_id', $user->id)
            ->with('card.deck.noteType')
            ->each(function (SrsReviewState $state) use (&$map): void {
                if (! self::isMature($state)) {
                    return;
                }
                $card = $state->card;
                if ($card === null) {
                    return;
                }
                $shape = self::shapeKey($card->deck, $card->source_word_id, $card->fields);
                if ($shape !== null) {
                    $map[$shape][$card->id] = $card->id;
                }
            });

        return $map;
    }

    /**
     * Ключ формы: тип заметки + лемма. Для карточек урока без леммы —
     * тип заметки + нормализованный front. Карточки без обоих ключей
     * (парадигмы/грамматика/аудио без леммы) не участвуют в дедупе (R4/Q16).
     */
    public static function shapeKey(?SrsDeck $deck, ?int $sourceWordId, mixed $fields): ?string
    {
        $noteKey = $deck?->noteType?->key;
        if ($noteKey === null || $noteKey === '') {
            return null;
        }
        if ($sourceWordId !== null) {
            return $noteKey.':s'.$sourceWordId;
        }
        $front = is_array($fields) ? trim((string) ($fields['front'] ?? '')) : '';
        if ($front === '') {
            return null;
        }

        return $noteKey.':f'.mb_strtolower($front);
    }

    /**
     * Создание карточки системным импортом (путь `SrsCard::create`, как в
     * LessonFlashCardsSync). Возвращает null, когда форма уже есть в ДРУГОЙ
     * колоде и флаг ВКЛ — вызывающий считает `skipped`. При флаге OFF это
     * ровно `SrsCard::create`.
     */
    public function createCard(SrsDeck $deck, ?int $sourceWordId, array $attributes): ?SrsCard
    {
        if (self::enabled() && $this->duplicateElsewhere($deck, $sourceWordId, $attributes)) {
            return null;
        }

        return SrsCard::create([
            'deck_id' => $deck->id,
            'source_word_id' => $sourceWordId,
        ] + $attributes);
    }

    /**
     * Импорт из словаря (путь `SrsCard::firstOrCreate` по deck+lemma).
     * При флаге OFF это ровно исходный `firstOrCreate`.
     */
    public function firstOrCreateCard(SrsDeck $deck, ?int $sourceWordId, array $attributes): ?SrsCard
    {
        if (self::enabled() && $this->duplicateElsewhere($deck, $sourceWordId, $attributes)) {
            return null;
        }

        return SrsCard::firstOrCreate(
            ['deck_id' => $deck->id, 'source_word_id' => $sourceWordId],
            $attributes,
        );
    }

    private function duplicateElsewhere(SrsDeck $deck, ?int $sourceWordId, array $attributes): bool
    {
        $shape = self::shapeKey($deck, $sourceWordId, $attributes['fields'] ?? []);

        return $shape !== null && $this->shapeExistsElsewhere($shape, $deck->id);
    }

    /**
     * front-формы сверяются в самом импорте (in-process): JSON-доступ к
     * `fields` различается между sqlite и MySQL, а БД-запрос тут не нужен.
     */
    private function shapeExistsElsewhere(string $shape, int $deckId): bool
    {
        [$noteKey, $local] = array_pad(explode(':', $shape, 2), 2, '');
        if (! str_starts_with($local, 's')) {
            return false;
        }

        return SrsCard::query()
            ->where('deck_id', '!=', $deckId)
            ->where('source_word_id', (int) substr($local, 1))
            ->whereHas('deck.noteType', fn ($q) => $q->where('key', $noteKey))
            ->exists();
    }
}
