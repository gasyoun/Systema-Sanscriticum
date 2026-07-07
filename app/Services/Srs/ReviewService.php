<?php

declare(strict_types=1);

namespace App\Services\Srs;

use App\Models\SrsCard;
use App\Models\SrsDeck;
use App\Models\SrsReviewLog;
use App\Models\SrsReviewState;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Мост между Eloquent-слоем SRS и чистым движком {@see Fsrs}. Строит очередь на
 * сегодня (просроченные + лимит новых) и применяет оценку карточки (обновляет
 * srs_review_states, пишет append-only строку в srs_review_logs).
 */
class ReviewService
{
    private Fsrs $fsrs;

    public function __construct(?Fsrs $fsrs = null)
    {
        $this->fsrs = $fsrs ?? new Fsrs(
            desiredRetention: (float) config('srs.desired_retention', 0.9),
            enableFuzzing: (bool) config('srs.fuzz', true),
        );
    }

    /**
     * Очередь карточек колоды для пользователя на «сейчас»: сперва просроченные
     * (по возрастанию due), затем новые в пределах дневного лимита.
     *
     * @return Collection<int, SrsCard>
     */
    public function queueFor(User $user, SrsDeck $deck, ?DateTimeImmutable $now = null): Collection
    {
        $now = $now ? Carbon::instance($now) : Carbon::now();

        $due = SrsCard::query()
            ->where('deck_id', $deck->id)
            ->whereHas('reviewStates', fn ($q) => $q
                ->where('user_id', $user->id)
                ->where('due', '<=', $now))
            ->with(['reviewStates' => fn ($q) => $q->where('user_id', $user->id)])
            ->get()
            ->sortBy(fn (SrsCard $c) => optional($c->reviewStates->first())->due)
            ->values();

        $remaining = max(0, $this->newPerDay($user, $deck) - $this->newSeenToday($user, $deck, $now));

        $new = $remaining > 0
            ? SrsCard::query()
                ->where('deck_id', $deck->id)
                ->whereDoesntHave('reviewStates', fn ($q) => $q->where('user_id', $user->id))
                ->orderBy('id')
                ->limit($remaining)
                ->get()
            : collect();

        return $due->concat($new)->values();
    }

    /** Сколько карточек всего ждут показа прямо сейчас (для бейджа/итогов). */
    public function dueCount(User $user, SrsDeck $deck, ?DateTimeImmutable $now = null): int
    {
        return $this->queueFor($user, $deck, $now)->count();
    }

    /**
     * Применяет оценку карточки: пересчитывает FSRS-состояние, сохраняет его и
     * дописывает журнал. Возвращает обновлённое состояние.
     */
    public function grade(
        User $user,
        SrsCard $card,
        Rating $rating,
        ?int $elapsedMs = null,
        ?DateTimeImmutable $reviewedAt = null,
    ): SrsReviewState {
        $reviewedAt = $reviewedAt ?? new DateTimeImmutable('now');

        $state = SrsReviewState::firstOrNew([
            'user_id' => $user->id,
            'card_id' => $card->id,
        ]);

        $fsrsCard = $this->toFsrsCard($state, $reviewedAt);
        $wasReviewState = $fsrsCard->state === State::Review;

        $updated = $this->fsrs->reviewCard($fsrsCard, $rating, $reviewedAt);

        $state->stability = $updated->stability;
        $state->difficulty = $updated->difficulty;
        $state->due = $updated->due;
        $state->state = $updated->state->value;
        $state->step = $updated->step;
        $state->last_review = $updated->lastReview;
        $state->reps = ((int) ($state->reps ?? 0)) + 1;
        if ($rating === Rating::Again && $wasReviewState) {
            $state->lapses = ((int) ($state->lapses ?? 0)) + 1;
        }
        $state->save();

        $scheduledDays = intdiv($updated->due->getTimestamp() - $reviewedAt->getTimestamp(), 86400);

        SrsReviewLog::create([
            'user_id' => $user->id,
            'card_id' => $card->id,
            'deck_id' => $card->deck_id,
            'rating' => $rating->value,
            'state' => $updated->state->value,
            'elapsed_ms' => $elapsedMs,
            'scheduled_days' => $scheduledDays,
            'stability_after' => $updated->stability,
            'difficulty_after' => $updated->difficulty,
            'reviewed_at' => $reviewedAt,
        ]);

        return $state;
    }

    private function toFsrsCard(SrsReviewState $state, DateTimeImmutable $now): FsrsCard
    {
        if (! $state->exists || $state->last_review === null) {
            return FsrsCard::fresh($now);
        }

        return new FsrsCard(
            state: State::from((int) $state->state),
            step: $state->step,
            stability: $state->stability,
            difficulty: $state->difficulty,
            due: $state->due?->toDateTimeImmutable(),
            lastReview: $state->last_review?->toDateTimeImmutable(),
        );
    }

    private function newPerDay(User $user, SrsDeck $deck): int
    {
        $subscriber = $deck->subscribers()->where('users.id', $user->id)->first();
        $perDay = $subscriber?->pivot?->new_per_day;

        return $perDay !== null ? (int) $perDay : (int) config('srs.new_per_day', 20);
    }

    private function newSeenToday(User $user, SrsDeck $deck, Carbon $now): int
    {
        return SrsReviewState::query()
            ->where('user_id', $user->id)
            ->whereHas('card', fn ($q) => $q->where('deck_id', $deck->id))
            ->whereDate('created_at', $now->toDateString())
            ->count();
    }
}
