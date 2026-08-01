<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\SrsDeck;
use App\Models\SrsReviewLog;
use App\Models\SrsReviewState;
use App\Services\Srs\Rating;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * SRS-карточки (H211, Wave 1). Тонкая обёртка: страница личного кабинета /
 * публичной пробы, встраивающая Livewire-компонент {@see SrsReview}.
 * Доступна только при включённом флаге srs.enabled (маршрут регистрируется
 * под тем же условием).
 *
 * Public trial: /koloda and /koloda/{slug} — system/public decks without auth.
 * Cabinet: /dvaram/koloda (hub) + /dvaram/koloda/{slug} (review) — auth required.
 * Legacy /srs and /dvaram/srs redirect 301 to the koloda paths.
 */
class SrsController extends Controller
{
    /**
     * Public hub of system/public decks (marketing trial, no login).
     */
    public function publicIndex(Request $request): View
    {
        abort_unless((bool) config('srs.enabled'), 404);

        $lang = $this->resolveLanguageFilter($request);

        $decks = SrsDeck::query()
            ->whereIn('visibility', ['system', 'public'])
            ->tap(fn ($q) => $this->applyLanguageFilter($q, $lang))
            ->withCount('cards')
            ->orderByRaw("CASE WHEN visibility = 'system' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->get();

        return view('srs.public-index', [
            'decks' => $decks,
            'lang' => $lang,
        ]);
    }

    /**
     * Public per-deck review (guest trial or logged-in on the shareable URL).
     */
    public function publicReview(string $slug): View
    {
        abort_unless((bool) config('srs.enabled'), 404);

        return view('srs.public-review', ['slug' => $slug]);
    }

    /**
     * Cabinet hub: list decks with per-deck URLs (auth).
     */
    public function cabinetIndex(Request $request): View
    {
        abort_unless((bool) config('srs.enabled'), 404);

        $userId = Auth::id();
        $lang = $this->resolveLanguageFilter($request);

        $decks = SrsDeck::query()
            ->where(function ($q) use ($userId) {
                $q->whereIn('visibility', ['system', 'public'])
                    ->orWhere('user_id', $userId);
            })
            ->tap(fn ($q) => $this->applyLanguageFilter($q, $lang))
            ->withCount('cards')
            ->orderByRaw("CASE WHEN visibility = 'system' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->get();

        return view('student.srs-hub', [
            'decks' => $decks,
            'lang' => $lang,
        ]);
    }

    /**
     * Cabinet per-deck review (auth) — URL is /dvaram/koloda/{slug}.
     */
    public function review(string $slug): View
    {
        abort_unless((bool) config('srs.enabled'), 404);

        return view('student.srs', ['slug' => $slug]);
    }

    /**
     * H1487 Wave 2 — student private-deck authoring UI (Livewire SrsDeckEditor).
     */
    public function decks(): View
    {
        abort_unless((bool) config('srs.enabled'), 404);

        return view('student.srs-decks');
    }

    /**
     * H447 — per-trainer stats dashboard. Progress bar per deck (cards with
     * a review state vs total cards in the deck), correct/incorrect ratio,
     * and a short error list (last 10 non-Good/Easy grades) sourced entirely
     * from srs_review_logs / srs_review_states — no new tables, per the
     * design doc's "the data is all there; only the view is missing".
     */
    public function stats(): View
    {
        abort_unless((bool) config('srs.enabled'), 404);

        $userId = Auth::id();

        $decks = SrsDeck::query()
            ->where(function ($q) use ($userId) {
                $q->whereIn('visibility', ['system', 'public'])
                    ->orWhere('user_id', $userId);
            })
            ->withCount('cards')
            ->orderByRaw("CASE WHEN visibility = 'system' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->get();

        $stats = $decks
            ->map(function (SrsDeck $deck) use ($userId) {
                $totalCards = $deck->cards_count;

                $reviewedCards = SrsReviewState::query()
                    ->where('user_id', $userId)
                    ->whereHas('card', fn ($q) => $q->where('deck_id', $deck->id))
                    ->count();

                $totalReviews = SrsReviewLog::query()
                    ->where('user_id', $userId)
                    ->where('deck_id', $deck->id)
                    ->count();

                $correct = SrsReviewLog::query()
                    ->where('user_id', $userId)
                    ->where('deck_id', $deck->id)
                    ->where('rating', '>=', Rating::Good->value)
                    ->count();

                $incorrect = $totalReviews - $correct;

                $errors = SrsReviewLog::query()
                    ->where('user_id', $userId)
                    ->where('deck_id', $deck->id)
                    ->where('rating', '<', Rating::Good->value)
                    ->with('card')
                    ->latest('reviewed_at')
                    ->limit(10)
                    ->get();

                return [
                    'deck' => $deck,
                    'total_cards' => $totalCards,
                    'reviewed_cards' => $reviewedCards,
                    'progress_pct' => $totalCards > 0 ? (int) round($reviewedCards / $totalCards * 100) : 0,
                    'total_reviews' => $totalReviews,
                    'correct' => $correct,
                    'incorrect' => $incorrect,
                    'accuracy_pct' => $totalReviews > 0 ? (int) round($correct / $totalReviews * 100) : 0,
                    'errors' => $errors,
                ];
            })
            ->filter(fn (array $s) => $s['total_cards'] > 0)
            ->values();

        return view('student.srs-stats', ['stats' => $stats]);
    }

    /**
     * H1992 — hub language filter. Default sa: null language treated as Sanskrit.
     * ?lang=sa|hi|all
     */
    private function resolveLanguageFilter(Request $request): string
    {
        $lang = strtolower((string) $request->query('lang', 'sa'));

        return in_array($lang, ['sa', 'hi', 'all'], true) ? $lang : 'sa';
    }

    /**
     * @param  Builder<SrsDeck>  $query
     * @return Builder<SrsDeck>
     */
    private function applyLanguageFilter($query, string $lang)
    {
        if ($lang === 'all') {
            return $query;
        }

        if ($lang === 'sa') {
            return $query->where(function ($q) {
                $q->where('language', 'sa')->orWhereNull('language');
            });
        }

        return $query->where('language', $lang);
    }
}
