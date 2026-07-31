<?php

declare(strict_types=1);

namespace App\Services\Srs;

use App\Models\SrsCard;
use App\Models\SrsDeck;
use Illuminate\Support\Collection;

/**
 * Sample wrong multiple-choice answers from the same deck (Memrise P2).
 * Prefers similar answer length; never includes the correct card's answer text.
 */
final class DistractorSampler
{
    /**
     * @return list<string> exactly $count unique wrong answers when the deck has enough cards
     */
    public function sample(SrsDeck $deck, SrsCard $correct, int $count = 3): array
    {
        $correctAnswer = CardFace::answer($correct);
        if ($correctAnswer === '' || $count < 1) {
            return [];
        }

        $correctLen = mb_strlen($correctAnswer);

        /** @var Collection<int, SrsCard> $others */
        $others = SrsCard::query()
            ->where('deck_id', $deck->id)
            ->where('id', '!=', $correct->id)
            ->get();

        $candidates = $others
            ->map(fn (SrsCard $c) => CardFace::answer($c))
            ->filter(fn (string $a) => $a !== '' && mb_strtolower($a) !== mb_strtolower($correctAnswer))
            ->unique(fn (string $a) => mb_strtolower($a))
            ->values();

        if ($candidates->isEmpty()) {
            return [];
        }

        $sorted = $candidates
            ->sortBy(fn (string $a) => abs(mb_strlen($a) - $correctLen))
            ->values();

        $picked = $sorted->take($count)->all();

        // If the deck is thin, pad is impossible — return what we have.
        return array_values($picked);
    }

    /**
     * Build a shuffled list of choice strings including the correct answer.
     *
     * @return list<array{text: string, correct: bool}>
     */
    public function choices(SrsDeck $deck, SrsCard $correct, int $wrongCount = 3): array
    {
        $answer = CardFace::answer($correct);
        $items = [['text' => $answer, 'correct' => true]];

        foreach ($this->sample($deck, $correct, $wrongCount) as $wrong) {
            $items[] = ['text' => $wrong, 'correct' => false];
        }

        shuffle($items);

        return $items;
    }
}
