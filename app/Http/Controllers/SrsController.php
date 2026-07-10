<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Livewire\SrsReview;
use App\Models\SrsDeck;
use App\Models\SrsReviewLog;
use App\Models\SrsReviewState;
use App\Services\Srs\Rating;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * SRS-карточки (H211, Wave 1). Тонкая обёртка: страница личного кабинета,
 * встраивающая Livewire-компонент {@see SrsReview}. Доступна только
 * при включённом флаге srs.enabled (маршрут регистрируется под тем же условием).
 */
class SrsController extends Controller
{
    public function review(): View
    {
        abort_unless((bool) config('srs.enabled'), 404);

        return view('student.srs');
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
}
