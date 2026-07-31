<?php

declare(strict_types=1);

namespace Tests\Feature\Srs;

use App\Livewire\SrsReview;
use App\Models\SrsCard;
use App\Models\SrsDeck;
use App\Models\SrsNoteType;
use App\Models\SrsReviewState;
use App\Models\User;
use App\Services\Srs\AnswerMatcher;
use App\Services\Srs\CardFace;
use App\Services\Srs\DistractorSampler;
use App\Services\Srs\Rating;
use App\Services\Srs\ReviewService;
use App\Services\Srs\State;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Memrise P2 — multiple choice, typing, pairs, speed timeout, difficult queue.
 */
class SrsTestModesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['srs.enabled' => true]);
    }

    private function makeDeckWithCards(int $n = 5): SrsDeck
    {
        $noteType = SrsNoteType::create([
            'key' => 'sanskrit_basic',
            'name' => 'Санскрит',
            'language' => 'sa',
            'fields' => ['devanagari', 'iast', 'translation'],
        ]);

        $deck = SrsDeck::create([
            'note_type_id' => $noteType->id,
            'name' => 'Test deck',
            'slug' => 'test-deck',
            'language' => 'sa',
            'visibility' => 'system',
        ]);

        $words = [
            ['देव', 'deva', 'бог'],
            ['सत्य', 'satya', 'истина'],
            ['ज्ञान', 'jñāna', 'знание'],
            ['कर्म', 'karma', 'действие'],
            ['धर्म', 'dharma', 'долг'],
            ['योग', 'yoga', 'йога'],
        ];

        for ($i = 0; $i < $n; $i++) {
            $w = $words[$i % count($words)];
            SrsCard::create([
                'deck_id' => $deck->id,
                'direction' => 'front_back',
                'fields' => [
                    'devanagari' => $w[0].($i >= count($words) ? (string) $i : ''),
                    'iast' => $w[1].($i >= count($words) ? (string) $i : ''),
                    'translation' => $w[2].($i >= count($words) ? " {$i}" : ''),
                ],
            ]);
        }

        return $deck->fresh();
    }

    public function test_card_face_resolves_translation_ru(): void
    {
        $noteType = SrsNoteType::create([
            'key' => 't', 'name' => 't', 'language' => 'sa', 'fields' => ['iast', 'translation_ru'],
        ]);
        $deck = SrsDeck::create([
            'note_type_id' => $noteType->id, 'name' => 'd', 'language' => 'sa', 'visibility' => 'system',
        ]);
        $card = SrsCard::create([
            'deck_id' => $deck->id,
            'direction' => 'front_back',
            'fields' => ['iast' => 'na', 'translation_ru' => 'не'],
        ]);

        $this->assertSame('na', CardFace::prompt($card));
        $this->assertSame('не', CardFace::answer($card));
    }

    public function test_distractor_sampler_excludes_correct_answer(): void
    {
        $deck = $this->makeDeckWithCards(5);
        $correct = $deck->cards()->first();
        $sampler = new DistractorSampler;
        $choices = $sampler->choices($deck, $correct, 3);

        $this->assertGreaterThanOrEqual(2, count($choices));
        $corrects = array_filter($choices, fn ($c) => $c['correct']);
        $this->assertCount(1, $corrects);
        $wrong = array_filter($choices, fn ($c) => ! $c['correct']);
        foreach ($wrong as $w) {
            $this->assertNotSame(CardFace::answer($correct), $w['text']);
        }
    }

    public function test_answer_matcher_exact_soft_miss(): void
    {
        $deck = $this->makeDeckWithCards(1);
        $card = $deck->cards()->first();
        $m = new AnswerMatcher;

        // First card in makeDeckWithCards is deva → «бог».
        $this->assertSame(AnswerMatcher::EXACT, $m->match($card, 'бог'));
        $this->assertSame(AnswerMatcher::EXACT, $m->match($card, '  БОГ  '));
        $this->assertSame(AnswerMatcher::SOFT, $m->match($card, 'бох'));
        $this->assertSame(AnswerMatcher::MISS, $m->match($card, 'собака'));
    }

    public function test_mc_mode_correct_choice_grades_good(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeckWithCards(5);

        $component = Livewire::actingAs($user)
            ->withQueryParams(['mode' => 'mc'])
            ->test(SrsReview::class, ['slug' => 'test-deck']);

        $component->assertSet('mode', 'mc');
        $choices = $component->get('mcChoices');
        $this->assertNotEmpty($choices);

        $correctIndex = null;
        foreach ($choices as $i => $c) {
            if ($c['correct']) {
                $correctIndex = $i;
                break;
            }
        }
        $this->assertNotNull($correctIndex);

        $component->call('chooseMc', $correctIndex);

        $this->assertDatabaseHas('srs_review_logs', [
            'user_id' => $user->id,
            'rating' => Rating::Good->value,
        ]);
    }

    public function test_typing_mode_exact_grades_good(): void
    {
        $user = User::factory()->create();
        $this->makeDeckWithCards(3);

        Livewire::actingAs($user)
            ->withQueryParams(['mode' => 'typing'])
            ->test(SrsReview::class, ['slug' => 'test-deck'])
            ->assertSet('mode', 'typing')
            ->set('typedAnswer', 'бог')
            ->call('submitTyping');

        $this->assertDatabaseHas('srs_review_logs', [
            'user_id' => $user->id,
            'rating' => Rating::Good->value,
        ]);
    }

    public function test_difficult_queue_only_lapsed_cards(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeckWithCards(3);
        $cards = $deck->cards()->orderBy('id')->get();

        // Mark first card as lapsed.
        SrsReviewState::create([
            'user_id' => $user->id,
            'card_id' => $cards[0]->id,
            'stability' => 1.0,
            'difficulty' => 5.0,
            'due' => now()->subDay(),
            'reps' => 3,
            'lapses' => 2,
            'state' => State::Review->value,
            'step' => null,
            'last_review' => now()->subDay(),
        ]);

        // Second card reviewed without lapses.
        SrsReviewState::create([
            'user_id' => $user->id,
            'card_id' => $cards[1]->id,
            'stability' => 2.0,
            'difficulty' => 4.0,
            'due' => now()->subHour(),
            'reps' => 2,
            'lapses' => 0,
            'state' => State::Review->value,
            'step' => null,
            'last_review' => now()->subHour(),
        ]);

        $queue = app(ReviewService::class)->queueDifficultFor($user, $deck);
        $this->assertCount(1, $queue);
        $this->assertSame($cards[0]->id, $queue->first()->id);
    }

    public function test_speed_timeout_grades_again(): void
    {
        $user = User::factory()->create();
        $this->makeDeckWithCards(2);

        Livewire::actingAs($user)
            ->withQueryParams(['mode' => 'speed'])
            ->test(SrsReview::class, ['slug' => 'test-deck'])
            ->assertSet('mode', 'speed')
            ->call('speedTimeout');

        $this->assertDatabaseHas('srs_review_logs', [
            'user_id' => $user->id,
            'rating' => Rating::Again->value,
        ]);
    }

    public function test_pairs_mode_builds_columns(): void
    {
        $user = User::factory()->create();
        $this->makeDeckWithCards(4);

        $c = Livewire::actingAs($user)
            ->withQueryParams(['mode' => 'pairs'])
            ->test(SrsReview::class, ['slug' => 'test-deck']);

        $c->assertSet('mode', 'pairs');
        $this->assertGreaterThanOrEqual(2, count($c->get('pairLeft')));
        $this->assertSame(count($c->get('pairLeft')), count($c->get('pairRight')));
    }

    public function test_mode_tabs_visible_for_auth_user(): void
    {
        $user = User::factory()->create();
        $this->makeDeckWithCards(2);

        Livewire::actingAs($user)
            ->test(SrsReview::class, ['slug' => 'test-deck'])
            ->assertSee('Классика')
            ->assertSee('Выбор')
            ->assertSee('Ввод')
            ->assertSee('Пары')
            ->assertSee('Скорость')
            ->assertSee('Сложные');
    }
}
