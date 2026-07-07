<?php

declare(strict_types=1);

namespace Tests\Feature\Srs;

use App\Models\SrsCard;
use App\Models\SrsDeck;
use App\Models\SrsNoteType;
use App\Models\User;
use App\Services\Srs\Fsrs;
use App\Services\Srs\Rating;
use App\Services\Srs\ReviewService;
use App\Services\Srs\State;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeDeck(int $cardCount): SrsDeck
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

    /** Детерминированный сервис (fuzz off) для стабильных сроков в тестах. */
    private function service(): ReviewService
    {
        return new ReviewService(new Fsrs(enableFuzzing: false));
    }

    public function test_grade_creates_state_and_log_and_advances_due(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck(1);
        $card = $deck->cards()->first();
        $now = new DateTimeImmutable('2026-07-07 10:00:00');

        $state = $this->service()->grade($user, $card, Rating::Good, 1500, $now);

        $this->assertDatabaseHas('srs_review_states', [
            'user_id' => $user->id,
            'card_id' => $card->id,
        ]);
        $this->assertDatabaseHas('srs_review_logs', [
            'user_id' => $user->id,
            'card_id' => $card->id,
            'rating' => Rating::Good->value,
            'elapsed_ms' => 1500,
        ]);
        $this->assertSame(1, $state->reps);
        $this->assertGreaterThan($now->getTimestamp(), $state->due->getTimestamp());
    }

    public function test_new_per_day_limit_caps_queue(): void
    {
        config(['srs.new_per_day' => 3]);

        $user = User::factory()->create();
        $deck = $this->makeDeck(10);

        $queue = $this->service()->queueFor($user, $deck);

        $this->assertCount(3, $queue);
    }

    public function test_graded_card_leaves_queue_then_returns_when_due(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck(1);
        $card = $deck->cards()->first();
        $svc = $this->service();
        $now = new DateTimeImmutable('2026-07-07 10:00:00');

        $svc->grade($user, $card, Rating::Good, null, $now);

        // Сразу после оценки карточка не в очереди (срок ещё не наступил, новой уже не считается).
        $this->assertCount(0, $svc->queueFor($user, $deck, $now));

        // Спустя достаточно времени — снова показывается.
        $later = $now->modify('+1 year');
        $this->assertCount(1, $svc->queueFor($user, $deck, $later));
    }

    public function test_again_in_review_increments_lapses(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck(1);
        $card = $deck->cards()->first();
        $svc = $this->service();

        // Прогоняем карточку до состояния Review серией Easy (в разные дни).
        $t = new DateTimeImmutable('2026-01-01 10:00:00');
        $state = null;
        for ($i = 0; $i < 4; $i++) {
            $state = $svc->grade($user, $card, Rating::Easy, null, $t);
            $t = $state->due->toDateTimeImmutable();
        }
        $this->assertSame(State::Review->value, $state->state);
        $this->assertSame(0, $state->lapses);

        // Провал в Review → lapse.
        $state = $svc->grade($user, $card, Rating::Again, null, $t);
        $this->assertSame(1, $state->lapses);
    }
}
