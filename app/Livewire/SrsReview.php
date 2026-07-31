<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\SrsCard;
use App\Models\SrsDeck;
use App\Services\Srs\Rating;
use App\Services\Srs\ReviewService;
use App\Services\Srs\SrsMedia;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * SRS-обзор (H211, Wave 1): подбор колоды → лицо карточки → раскрытие →
 * оценка Again/Hard/Good/Easy с FSRS-предсказанием интервала. Ядро логики — в
 * {@see ReviewService}; компонент только держит UI-состояние (выбранная колода,
 * раскрыта ли карточка).
 *
 * Per-deck URL + guest trial: mount accepts optional slug; changing the deck
 * select navigates to /koloda/{slug} or /dvaram/koloda/{slug}. Guests may try
 * system/public decks without registration (session-only, no FSRS persist).
 */
class SrsReview extends Component
{
    public ?int $deckId = null;

    public bool $revealed = false;

    /** Guest trial: cards already graded in this Livewire session (not persisted). */
    public int $guestGraded = 0;

    public bool $isGuest = false;

    public function mount(?string $slug = null): void
    {
        abort_unless((bool) config('srs.enabled'), 404);

        $this->isGuest = ! auth()->check();

        if ($slug !== null && $slug !== '') {
            $deck = $this->resolveDeckBySlug($slug);
            abort_unless($deck !== null && $this->canAccess($deck), 404);
            $this->deckId = $deck->id;

            return;
        }

        $this->deckId = $this->availableDecks()->value('id');
    }

    /**
     * URL path segment for a deck: slug when present, else id-{id} for private
     * decks without a slug.
     */
    public static function deckPathSegment(SrsDeck $deck): string
    {
        return $deck->slug !== null && $deck->slug !== ''
            ? $deck->slug
            : 'id-'.$deck->id;
    }

    /** Language-aware marketing line (sa → санскрит, hi → хинди). */
    public function tagline(?SrsDeck $deck): string
    {
        $subject = match ($deck?->language) {
            'hi' => 'хинди',
            'sa' => 'санскрит',
            default => 'слова',
        };

        return "Интервальные повторения — учите {$subject} по чуть-чуть каждый день.";
    }

    private function resolveDeckBySlug(string $slug): ?SrsDeck
    {
        if (str_starts_with($slug, 'id-')) {
            $id = (int) substr($slug, 3);

            return $id > 0 ? SrsDeck::find($id) : null;
        }

        return SrsDeck::query()->where('slug', $slug)->first();
    }

    private function canAccess(SrsDeck $deck): bool
    {
        if (in_array($deck->visibility, ['system', 'public'], true)) {
            return true;
        }

        return auth()->check() && (int) $deck->user_id === (int) auth()->id();
    }

    /** Колоды, доступные: системные, публичные (+ свои, если auth). */
    private function availableDecks(): Builder
    {
        $userId = auth()->id();

        return SrsDeck::query()
            ->where(function (Builder $q) use ($userId) {
                $q->whereIn('visibility', ['system', 'public']);
                if ($userId !== null) {
                    $q->orWhere('user_id', $userId);
                }
            })
            ->orderByRaw("CASE WHEN visibility = 'system' THEN 0 ELSE 1 END")
            ->orderBy('name');
    }

    private function currentDeck(): ?SrsDeck
    {
        return $this->deckId ? SrsDeck::find($this->deckId) : null;
    }

    /**
     * When the deck select changes, navigate so the URL reflects the deck.
     * Livewire only fires this after mount, so no redirect loop on first load.
     */
    public function updatedDeckId(mixed $value): void
    {
        $deck = SrsDeck::find((int) $value);
        if ($deck === null || ! $this->canAccess($deck)) {
            return;
        }

        $this->revealed = false;
        $this->guestGraded = 0;

        $segment = self::deckPathSegment($deck);
        $path = ($this->isGuest || request()->routeIs('srs.*'))
            ? '/koloda/'.$segment
            : '/dvaram/koloda/'.$segment;

        // url() not route(): unit tests may boot without SRS route registration.
        $this->redirect(url($path), navigate: true);
    }

    public function selectDeck(int $deckId): void
    {
        $this->deckId = $deckId;
        $this->updatedDeckId($deckId);
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

        if ($this->isGuest) {
            $this->guestGraded++;
            $this->revealed = false;

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

    /**
     * Public URL for a card audio/image field, or null if missing/unresolvable.
     * Used by the review Blade for Anki-imported media (H1970 follow-up).
     */
    public function mediaUrl(?string $path): ?string
    {
        return SrsMedia::url($path, $this->currentDeck());
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

    /**
     * Guest trial queue: first N cards of the deck, then slice past already-graded.
     *
     * @return Collection<int, SrsCard>
     */
    private function guestQueue(SrsDeck $deck): Collection
    {
        $limit = max(1, (int) config('srs.guest_trial_cards', 10));

        return SrsCard::query()
            ->where('deck_id', $deck->id)
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->slice($this->guestGraded)
            ->values();
    }

    public function render(): View
    {
        $deck = $this->currentDeck();
        $service = app(ReviewService::class);
        $guestLimit = max(1, (int) config('srs.guest_trial_cards', 10));

        if ($this->isGuest) {
            $queue = $deck !== null ? $this->guestQueue($deck) : collect();
            $guestLimitReached = $deck !== null && $this->guestGraded >= $guestLimit;
            $card = $guestLimitReached ? null : $queue->first();

            return view('livewire.srs-review', [
                'decks' => $this->availableDecks()->get(),
                'deck' => $deck,
                'card' => $card,
                'remaining' => $guestLimitReached ? 0 : $queue->count(),
                'previews' => [],
                'guestLimitReached' => $guestLimitReached,
                'tagline' => $this->tagline($deck),
                'isGuest' => true,
            ]);
        }

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
            'guestLimitReached' => false,
            'tagline' => $this->tagline($deck),
            'isGuest' => false,
        ]);
    }
}
