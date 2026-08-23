<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\SrsCard;
use App\Models\SrsDeck;
use App\Services\Srs\AnswerMatcher;
use App\Services\Srs\CardFace;
use App\Services\Srs\DistractorSampler;
use App\Services\Srs\Rating;
use App\Services\Srs\ReviewService;
use App\Services\Srs\SrsMedia;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * SRS review (H211 Wave 1 + Memrise P2 test modes).
 *
 * Per-deck URL + guest trial: mount accepts optional slug; changing the deck
 * select navigates to /koloda/{slug} or /dvaram/koloda/{slug}. Guests may try
 * system/public decks without registration (session-only, no FSRS persist).
 *
 * Modes (query ?mode= / Livewire $mode):
 * - classic — reveal → Again/Hard/Good/Easy (original)
 * - mc — multiple choice (distractors from same deck)
 * - typing — free-type answer (normalize + soft match)
 * - pairs — tap-the-pairs (match prompt↔answer for a small batch)
 * - speed — timed classic (deadline grades Again if not answered)
 * - difficult — same UI as classic, queue = lapses &gt; 0 only
 */
class SrsReview extends Component
{
    public const MODES = ['classic', 'mc', 'typing', 'pairs', 'speed', 'difficult'];

    /**
     * H3313: #[Locked] — клиент не может переписать deckId после mount
     * (иначе тамперинг открывал чужую приватную колоду). Легитимная смена
     * колоды — навигацией по URL (см. select в blade).
     */
    #[Locked]
    public ?int $deckId = null;

    public bool $revealed = false;

    /** Guest trial: cards already graded in this Livewire session (not persisted). */
    public int $guestGraded = 0;

    public bool $isGuest = false;

    /** @var string classic|mc|typing|pairs|speed|difficult */
    public string $mode = 'classic';

    /** MC: list of {text, correct} after shuffle. */
    public array $mcChoices = [];

    public ?string $mcFeedback = null;

    public string $typedAnswer = '';

    public ?string $typingFeedback = null;

    /** Pairs: left column (prompts), right column (answers), matched id pairs. */
    public array $pairLeft = [];

    public array $pairRight = [];

    public array $pairMatched = [];

    public ?int $pairSelectedLeft = null;

    public ?string $pairsFeedback = null;

    /** Speed: seconds remaining for current card. */
    public int $speedSeconds = 10;

    public int $speedLimit = 10;

    public function mount(?string $slug = null): void
    {
        abort_unless((bool) config('srs.enabled'), 404);

        $this->isGuest = ! auth()->check();

        $requested = (string) request()->query('mode', 'classic');
        $this->mode = in_array($requested, self::MODES, true) ? $requested : 'classic';

        // Guests: only classic trial (no FSRS / no multi-mode complexity).
        if ($this->isGuest) {
            $this->mode = 'classic';
        }

        if ($slug !== null && $slug !== '') {
            $deck = $this->resolveDeckBySlug($slug);
            abort_unless($deck !== null && $this->canAccess($deck), 404);
            $this->deckId = $deck->id;
            $this->prepareModeState();

            return;
        }

        $this->deckId = $this->availableDecks()->value('id');
        $this->prepareModeState();
    }

    public static function deckPathSegment(SrsDeck $deck): string
    {
        return $deck->slug !== null && $deck->slug !== ''
            ? $deck->slug
            : 'id-'.$deck->id;
    }

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
        // H3313 defense-in-depth: даже при подменённом deckId (hydrate-байпас)
        // чужая приватная колода не отдаётся — рендер/грейд получают null.
        $deck = $this->deckId ? SrsDeck::find($this->deckId) : null;

        return ($deck !== null && $this->canAccess($deck)) ? $deck : null;
    }

    public function updatedDeckId(mixed $value): void
    {
        $deck = SrsDeck::find((int) $value);
        if ($deck === null || ! $this->canAccess($deck)) {
            return;
        }

        $this->resetCardUi();
        $this->guestGraded = 0;

        $segment = self::deckPathSegment($deck);
        $path = ($this->isGuest || request()->routeIs('srs.*'))
            ? '/koloda/'.$segment
            : '/dvaram/koloda/'.$segment;

        if ($this->mode !== 'classic' && ! $this->isGuest) {
            $path .= '?mode='.$this->mode;
        }

        $this->redirect(url($path), navigate: true);
    }

    public function selectDeck(int $deckId): void
    {
        // H3313: как в SrsDeckEditor::selectDeck — гейт до присваивания.
        $deck = SrsDeck::find($deckId);
        abort_unless($deck !== null && $this->canAccess($deck), 403);

        $this->deckId = $deckId;
        $this->updatedDeckId($deckId);
    }

    public function setMode(string $mode): void
    {
        if ($this->isGuest || ! in_array($mode, self::MODES, true)) {
            return;
        }

        $this->mode = $mode;
        $this->resetCardUi();
        $this->prepareModeState();

        $deck = $this->currentDeck();
        if ($deck === null) {
            return;
        }

        $segment = self::deckPathSegment($deck);
        $path = request()->routeIs('srs.*')
            ? '/koloda/'.$segment
            : '/dvaram/koloda/'.$segment;
        if ($mode !== 'classic') {
            $path .= '?mode='.$mode;
        }
        $this->redirect(url($path), navigate: true);
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
            $this->resetCardUi();

            return;
        }

        $service = app(ReviewService::class);
        $card = $this->queue($service, $deck)->first();
        if ($card === null) {
            return;
        }

        $service->grade(auth()->user(), $card, Rating::from($rating));
        $this->resetCardUi();
        $this->prepareModeState();
    }

    /** Multiple-choice: pick index into $mcChoices. */
    public function chooseMc(int $index): void
    {
        if ($this->mode !== 'mc' || $this->isGuest) {
            return;
        }

        $choice = $this->mcChoices[$index] ?? null;
        if ($choice === null) {
            return;
        }

        $this->mcFeedback = ($choice['correct'] ?? false) ? 'correct' : 'wrong';
        $rating = ($choice['correct'] ?? false) ? Rating::Good : Rating::Again;
        $this->grade($rating->value);
    }

    /** Typing mode submit. */
    public function submitTyping(): void
    {
        if ($this->mode !== 'typing' || $this->isGuest) {
            return;
        }

        $deck = $this->currentDeck();
        if ($deck === null) {
            return;
        }

        $card = $this->queue(app(ReviewService::class), $deck)->first();
        if ($card === null) {
            return;
        }

        $result = app(AnswerMatcher::class)->match($card, $this->typedAnswer);
        $this->typingFeedback = $result;

        $rating = match ($result) {
            AnswerMatcher::EXACT => Rating::Good,
            AnswerMatcher::SOFT => Rating::Hard,
            default => Rating::Again,
        };

        $this->grade($rating->value);
    }

    public function selectPairLeft(int $id): void
    {
        if ($this->mode !== 'pairs' || in_array($id, $this->pairMatched, true)) {
            return;
        }
        $this->pairSelectedLeft = $id;
    }

    public function selectPairRight(int $id): void
    {
        if ($this->mode !== 'pairs' || $this->pairSelectedLeft === null) {
            return;
        }
        if (in_array($id, $this->pairMatched, true)) {
            return;
        }

        // Right ids equal left card ids when they form a true pair.
        if ($id === $this->pairSelectedLeft) {
            $this->pairMatched[] = $id;
            $this->pairSelectedLeft = null;
            $this->pairsFeedback = null;

            if (count($this->pairMatched) >= count($this->pairLeft)) {
                // Grade each matched card as Good.
                $this->finishPairsSession(Rating::Good);
            }

            return;
        }

        $this->pairsFeedback = 'wrong';
        $this->pairSelectedLeft = null;
    }

    private function finishPairsSession(Rating $rating): void
    {
        if ($this->isGuest) {
            $this->resetCardUi();

            return;
        }

        $deck = $this->currentDeck();
        if ($deck === null) {
            return;
        }

        $service = app(ReviewService::class);
        foreach ($this->pairLeft as $item) {
            $card = SrsCard::find($item['id'] ?? null);
            if ($card !== null) {
                $service->grade(auth()->user(), $card, $rating);
            }
        }

        $this->resetCardUi();
        $this->prepareModeState();
    }

    /** Speed mode: timer expired without grade. */
    public function speedTimeout(): void
    {
        if ($this->mode !== 'speed') {
            return;
        }
        $this->grade(Rating::Again->value);
    }

    public function mediaUrl(?string $path): ?string
    {
        return SrsMedia::url($path, $this->currentDeck());
    }

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

    /**
     * @return Collection<int, SrsCard>
     */
    private function queue(ReviewService $service, SrsDeck $deck): Collection
    {
        if ($this->mode === 'difficult') {
            return $service->queueDifficultFor(auth()->user(), $deck);
        }

        return $service->queueFor(auth()->user(), $deck);
    }

    private function resetCardUi(): void
    {
        $this->revealed = false;
        $this->mcChoices = [];
        $this->mcFeedback = null;
        $this->typedAnswer = '';
        $this->typingFeedback = null;
        $this->pairLeft = [];
        $this->pairRight = [];
        $this->pairMatched = [];
        $this->pairSelectedLeft = null;
        $this->pairsFeedback = null;
        $this->speedSeconds = $this->speedLimit;
    }

    private function prepareModeState(): void
    {
        if ($this->isGuest || $this->deckId === null) {
            return;
        }

        $deck = $this->currentDeck();
        if ($deck === null) {
            return;
        }

        $service = app(ReviewService::class);

        if ($this->mode === 'mc') {
            $card = $this->queue($service, $deck)->first();
            if ($card !== null) {
                $this->mcChoices = app(DistractorSampler::class)->choices($deck, $card, 3);
            }

            return;
        }

        if ($this->mode === 'pairs') {
            $batch = $this->queue($service, $deck)->take(4);
            if ($batch->count() < 2) {
                // Fall back to any cards in deck for a pairs drill.
                $batch = SrsCard::query()->where('deck_id', $deck->id)->orderBy('id')->limit(4)->get();
            }
            $left = [];
            $right = [];
            foreach ($batch as $card) {
                $prompt = CardFace::prompt($card);
                $answer = CardFace::answer($card);
                if ($prompt === '' || $answer === '') {
                    continue;
                }
                $left[] = ['id' => $card->id, 'text' => $prompt];
                $right[] = ['id' => $card->id, 'text' => $answer];
            }
            shuffle($right);
            $this->pairLeft = $left;
            $this->pairRight = $right;

            return;
        }

        if ($this->mode === 'speed') {
            $this->speedSeconds = $this->speedLimit;
        }
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
                'promptText' => $card ? CardFace::prompt($card) : '',
                'answerText' => $card ? CardFace::answer($card) : '',
                'modes' => self::MODES,
            ]);
        }

        if ($this->mode === 'pairs') {
            return view('livewire.srs-review', [
                'decks' => $this->availableDecks()->get(),
                'deck' => $deck,
                'card' => null,
                'remaining' => count($this->pairLeft) - count($this->pairMatched),
                'previews' => [],
                'guestLimitReached' => false,
                'tagline' => $this->tagline($deck),
                'isGuest' => false,
                'promptText' => '',
                'answerText' => '',
                'modes' => self::MODES,
            ]);
        }

        /** @var Collection $queue */
        $queue = $deck !== null
            ? $this->queue($service, $deck)
            : collect();

        $card = $queue->first();
        $previews = ($card !== null && $deck !== null)
            ? $service->previewIntervals(auth()->user(), $card)
            : [];

        // Rebuild MC choices if empty (e.g. after Livewire dehydrate edge).
        if ($this->mode === 'mc' && $card !== null && $deck !== null && $this->mcChoices === []) {
            $this->mcChoices = app(DistractorSampler::class)->choices($deck, $card, 3);
        }

        return view('livewire.srs-review', [
            'decks' => $this->availableDecks()->get(),
            'deck' => $deck,
            'card' => $card,
            'remaining' => $queue->count(),
            'previews' => $previews,
            'guestLimitReached' => false,
            'tagline' => $this->tagline($deck),
            'isGuest' => false,
            'promptText' => $card ? CardFace::prompt($card) : '',
            'answerText' => $card ? CardFace::answer($card) : '',
            'modes' => self::MODES,
        ]);
    }
}
