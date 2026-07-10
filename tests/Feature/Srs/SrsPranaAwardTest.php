<?php

declare(strict_types=1);

namespace Tests\Feature\Srs;

use App\Models\PranaTransaction;
use App\Models\SrsCard;
use App\Models\SrsDeck;
use App\Models\SrsNoteType;
use App\Models\SrsReviewLog;
use App\Models\User;
use App\Services\Prana\PranaService;
use App\Services\Srs\Fsrs;
use App\Services\Srs\Rating;
use App\Services\Srs\ReviewService;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H447 — Prana XP is awarded on every graded SRS review, right or wrong
 * (deliberate, anxiety-sensitive audience — see config/prana.php
 * 'srs_review' and Uprava/docs/SARASWATI_TRAINERS_2026.md §1), and a replay
 * of the exact same review-log row never double-awards (PranaService's
 * (user_id, reason, source_type, source_id) unique key).
 */
class SrsPranaAwardTest extends TestCase
{
    use RefreshDatabase;

    private function makeDeck(int $cardCount = 1): SrsDeck
    {
        $noteType = SrsNoteType::create([
            'key' => 'sanskrit_basic',
            'name' => 'Санскрит',
            'language' => 'sa',
            'fields' => ['devanagari', 'translation'],
        ]);

        $deck = SrsDeck::create([
            'note_type_id' => $noteType->id,
            'name' => 'Test deck',
            'language' => 'sa',
            'visibility' => 'system',
        ]);

        for ($i = 0; $i < $cardCount; $i++) {
            SrsCard::create([
                'deck_id' => $deck->id,
                'direction' => 'front_back',
                'fields' => ['devanagari' => "पद{$i}", 'translation' => "meaning {$i}"],
            ]);
        }

        return $deck;
    }

    private function service(): ReviewService
    {
        return new ReviewService(new Fsrs(enableFuzzing: false));
    }

    public function test_grade_awards_prana_xp_on_correct_answer(): void
    {
        $user = User::factory()->create();
        $card = $this->makeDeck()->cards()->first();

        $this->service()->grade($user, $card, Rating::Good, null, new DateTimeImmutable('2026-07-07 10:00:00'));

        $user->refresh();
        $expected = (int) config('prana.rewards.srs_review');
        $this->assertGreaterThan(0, $expected);
        $this->assertSame($expected, $user->prana_balance);
        $this->assertDatabaseHas('prana_transactions', [
            'user_id' => $user->id,
            'reason' => 'srs_review',
            'amount' => $expected,
        ]);
    }

    public function test_grade_awards_prana_xp_on_wrong_answer_too(): void
    {
        $user = User::factory()->create();
        $card = $this->makeDeck()->cards()->first();

        // Again = wrong answer. This must still award — copied from
        // Saraswati's "3 either way", removing fear of mistakes.
        $this->service()->grade($user, $card, Rating::Again, null, new DateTimeImmutable('2026-07-07 10:00:00'));

        $user->refresh();
        $expected = (int) config('prana.rewards.srs_review');
        $this->assertSame($expected, $user->prana_balance);
        $this->assertDatabaseHas('prana_transactions', [
            'user_id' => $user->id,
            'reason' => 'srs_review',
            'amount' => $expected,
        ]);
    }

    public function test_replaying_the_award_for_the_same_review_log_does_not_double_award(): void
    {
        $user = User::factory()->create();
        $card = $this->makeDeck()->cards()->first();

        $this->service()->grade($user, $card, Rating::Good, null, new DateTimeImmutable('2026-07-07 10:00:00'));

        $log = SrsReviewLog::where('user_id', $user->id)->where('card_id', $card->id)->firstOrFail();

        // Simulate a replay (e.g. a retried request/job) awarding for the
        // exact same log row a second time.
        $second = app(PranaService::class)->award($user, 'srs_review', $log);
        $this->assertFalse($second);

        $user->refresh();
        $expected = (int) config('prana.rewards.srs_review');
        $this->assertSame($expected, $user->prana_balance);
        $this->assertSame(
            1,
            PranaTransaction::where('user_id', $user->id)->where('reason', 'srs_review')->count()
        );
    }
}
