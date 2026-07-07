<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\SrsDeck;
use App\Services\Srs\Rating;
use App\Services\Srs\ReviewService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * SRS-обзор (H211, Wave 1): подбор колоды → лицо карточки → раскрытие →
 * оценка Again/Hard/Good/Easy с FSRS-предсказанием интервала. Ядро логики — в
 * {@see ReviewService}; компонент только держит UI-состояние (выбранная колода,
 * раскрыта ли карточка).
 */
class SrsReview extends Component
{
    public ?int $deckId = null;

    public bool $revealed = false;

    public function mount(): void
    {
        abort_unless((bool) config('srs.enabled'), 404);

        $this->deckId = $this->availableDecks()->value('id');
    }

    /** Колоды, доступные пользователю: системные, публичные и свои. */
    private function availableDecks(): Builder
    {
        $userId = auth()->id();

        return SrsDeck::query()
            ->where(function (Builder $q) use ($userId) {
                $q->whereIn('visibility', ['system', 'public'])
                    ->orWhere('user_id', $userId);
            })
            ->orderByRaw("CASE WHEN visibility = 'system' THEN 0 ELSE 1 END")
            ->orderBy('name');
    }

    private function currentDeck(): ?SrsDeck
    {
        return $this->deckId ? SrsDeck::find($this->deckId) : null;
    }

    public function selectDeck(int $deckId): void
    {
        $this->deckId = $deckId;
        $this->revealed = false;
    }

    public function reveal(): void
    {
        $this->revealed = true;
    }

    public function grade(int $rating): void
    {
        $deck = $this->currentDeck();
        if ($deck === null) {
            return;
        }

        $service = app(ReviewService::class);
        $card = $service->queueFor(auth()->user(), $deck)->first();
        if ($card === null) {
            return;
        }

        $service->grade(auth()->user(), $card, Rating::from($rating));
        $this->revealed = false;
    }

    /** Секунды → короткая русская подпись интервала для кнопок. */
    public function formatInterval(int $seconds): string
    {
        if ($seconds < 3600) {
            return max(1, intdiv($seconds, 60)).' мин';
        }
        if ($seconds < 86400) {
            return intdiv($seconds, 3600).' ч';
        }

        $days = intdiv($seconds, 86400);
        if ($days < 30) {
            return $days.' дн';
        }
        if ($days < 365) {
            return intdiv($days, 30).' мес';
        }

        return round($days / 365, 1).' г';
    }

    public function render(): View
    {
        $deck = $this->currentDeck();
        $service = app(ReviewService::class);

        /** @var Collection $queue */
        $queue = $deck !== null
            ? $service->queueFor(auth()->user(), $deck)
            : collect();

        $card = $queue->first();
        $previews = ($card !== null && $deck !== null)
            ? $service->previewIntervals(auth()->user(), $card)
            : [];

        return view('livewire.srs-review', [
            'decks' => $this->availableDecks()->get(),
            'deck' => $deck,
            'card' => $card,
            'remaining' => $queue->count(),
            'previews' => $previews,
        ]);
    }
}
