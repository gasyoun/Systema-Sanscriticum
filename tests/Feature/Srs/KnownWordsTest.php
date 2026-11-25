<?php

declare(strict_types=1);

namespace Tests\Feature\Srs;

use App\Models\Course;
use App\Models\Dictionary;
use App\Models\DictionaryWord;
use App\Models\Lesson;
use App\Models\SrsCard;
use App\Models\SrsDeck;
use App\Models\SrsNoteType;
use App\Models\SrsReviewState;
use App\Models\User;
use App\Services\Srs\KnownWords;
use App\Services\Srs\LessonFlashCardsSync;
use App\Services\Srs\ReviewService;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * H8599 — track Б: known-words. All five acceptance scenarios live here.
 */
class KnownWordsTest extends TestCase
{
    use RefreshDatabase;

    private function noteType(): SrsNoteType
    {
        return SrsNoteType::create([
            'key' => 'known_words_test',
            'name' => 'Known words test',
            'language' => 'sa',
            'fields' => ['front', 'back'],
        ]);
    }

    private function deck(SrsNoteType $noteType, string $slug): SrsDeck
    {
        return SrsDeck::create([
            'note_type_id' => $noteType->id,
            'name' => $slug,
            'slug' => $slug,
            'language' => 'sa',
            'visibility' => 'system',
        ]);
    }

    private function word(string $iast): DictionaryWord
    {
        $dict = Dictionary::firstOrCreate(['name' => 'H8599 test'], ['is_active' => true]);

        return DictionaryWord::create([
            'dictionary_id' => $dict->id,
            'devanagari' => 'सत्य',
            'iast' => $iast,
            'cyrillic' => 'сатья',
            'translation' => 'truth',
        ]);
    }

    private function card(SrsDeck $deck, ?DictionaryWord $word, string $front): SrsCard
    {
        return SrsCard::create([
            'deck_id' => $deck->id,
            'source_word_id' => $word?->id,
            'direction' => 'front_back',
            'fields' => ['front' => $front, 'back' => 'truth'],
        ]);
    }

    private function stateWithInterval(User $user, SrsCard $card, int $days): SrsReviewState
    {
        $now = new DateTimeImmutable('2026-11-25 10:00:00');

        return SrsReviewState::updateOrCreate(
            ['user_id' => $user->id, 'card_id' => $card->id],
            [
                'stability' => (float) $days,
                'difficulty' => 5.0,
                'due' => $now->modify(sprintf('+%d days', $days)),
                'last_review' => $now,
                'reps' => 1,
                'lapses' => 0,
                'state' => 2, // State::Review
                'step' => null,
            ],
        );
    }

    public function test_mature_twin_is_hidden_from_queue(): void
    {
        Config::set('srs.known_words.enabled', true);
        $user = User::factory()->create();
        $noteType = $this->noteType();
        $deckA = $this->deck($noteType, 'h8599-deck-a');
        $deckB = $this->deck($noteType, 'h8599-deck-b');
        $word = $this->word('satya');

        $twin = $this->card($deckA, $word, 'twin');
        $candidate = $this->card($deckB, $word, 'candidate');
        $this->stateWithInterval($user, $twin, 30);

        $queue = app(ReviewService::class)->queueFor($user, $deckB);

        $this->assertTrue($queue->doesntContain(fn (SrsCard $c) => $c->id === $candidate->id));
    }

    public function test_immature_twin_is_shown(): void
    {
        Config::set('srs.known_words.enabled', true);
        $user = User::factory()->create();
        $noteType = $this->noteType();
        $deckA = $this->deck($noteType, 'h8599-deck-a');
        $deckB = $this->deck($noteType, 'h8599-deck-b');
        $word = $this->word('satya');

        $twin = $this->card($deckA, $word, 'twin');
        $candidate = $this->card($deckB, $word, 'candidate');
        $this->stateWithInterval($user, $twin, 20); // under 21 days

        $queue = app(ReviewService::class)->queueFor($user, $deckB);

        $this->assertTrue($queue->contains(fn (SrsCard $c) => $c->id === $candidate->id));
    }

    public function test_other_note_type_is_not_a_twin(): void
    {
        Config::set('srs.known_words.enabled', true);
        $user = User::factory()->create();
        $word = $this->word('satya');
        $deckA = $this->deck($this->noteType(), 'h8599-deck-a');
        $otherType = SrsNoteType::create([
            'key' => 'known_words_other',
            'name' => 'Paradigm',
            'language' => 'sa',
            'fields' => ['front'],
        ]);
        $deckB = $this->deck($otherType, 'h8599-deck-b');

        $twin = $this->card($deckA, $word, 'twin');
        $candidate = $this->card($deckB, $word, 'candidate');
        $this->stateWithInterval($user, $twin, 120);

        $queue = app(ReviewService::class)->queueFor($user, $deckB);

        $this->assertTrue($queue->contains(fn (SrsCard $c) => $c->id === $candidate->id));
    }

    public function test_guest_and_flag_off_are_never_filtered(): void
    {
        $user = User::factory()->create();
        $word = $this->word('satya');
        $noteType = $this->noteType();
        $deckA = $this->deck($noteType, 'h8599-deck-a');
        $deckB = $this->deck($noteType, 'h8599-deck-b');
        $twin = $this->card($deckA, $word, 'twin');
        $candidate = $this->card($deckB, $word, 'candidate');
        $this->stateWithInterval($user, $twin, 60);
        $cards = collect([$candidate]);

        // Flag OFF: byte-identical to pre-H8599 behaviour.
        Config::set('srs.known_words.enabled', false);
        $this->assertSame($cards, app(KnownWords::class)->filterQueue($user, $cards));

        // Flag ON, but no persisted user id (guest): no personalization.
        Config::set('srs.known_words.enabled', true);
        $this->assertSame($cards, app(KnownWords::class)->filterQueue(new User, $cards));
    }

    public function test_maturity_uses_fsrs_interval_not_reps(): void
    {
        Config::set('srs.known_words.mature_days', 21);
        $user = User::factory()->create();
        $card = $this->card($this->deck($this->noteType(), 'h8599-deck'), null, 'x');

        $twenty = $this->stateWithInterval($user, $card, 20);
        $this->assertSame(20, KnownWords::intervalDays($twenty));
        $this->assertFalse(KnownWords::isMature($twenty));

        $twentyOne = $this->stateWithInterval($user, $card, 21);
        $this->assertTrue(KnownWords::isMature($twentyOne));

        // reps alone never makes a card mature.
        $manyReps = $this->stateWithInterval($user, $card, 3);
        $manyReps->reps = 500;
        $manyReps->save();
        $this->assertFalse(KnownWords::isMature($manyReps));
    }

    public function test_import_skips_same_shape_in_another_deck_only_when_flag_on(): void
    {
        $noteType = $this->noteType();
        $deckA = $this->deck($noteType, 'h8599-deck-a');
        $deckB = $this->deck($noteType, 'h8599-deck-b');
        $word = $this->word('satya');
        $this->card($deckA, $word, 'already here');

        $guard = app(KnownWords::class);
        $attributes = ['direction' => 'front_back', 'fields' => ['front' => 'again']];

        Config::set('srs.known_words.enabled', false);
        $created = $guard->firstOrCreateCard($deckB, $word->id, $attributes);
        $this->assertNotNull($created);
        $this->assertSame(2, SrsCard::count());

        Config::set('srs.known_words.enabled', true);
        $skipped = $guard->firstOrCreateCard($deckB, $word->id, $attributes);
        $this->assertNull($skipped);
        $this->assertSame(2, SrsCard::count());

        // A shape that exists ONLY in the target deck is not a duplicate:
        // firstOrCreate handles a re-import there (fields can refresh).
        $otherWord = $this->word('dharma');
        $deckC = $this->deck($noteType, 'h8599-deck-c');
        $first = $guard->firstOrCreateCard($deckC, $otherWord->id, $attributes);
        $again = $guard->firstOrCreateCard($deckC, $otherWord->id, $attributes);
        $this->assertNotNull($first);
        $this->assertNotNull($again);
        $this->assertSame(3, SrsCard::count());
    }

    public function test_lesson_import_reports_skipped_same_shape_cards(): void
    {
        Config::set('srs.known_words.enabled', true);
        $course = Course::factory()->create();
        $lesson = Lesson::factory()->for($course)->create([
            'flash_cards' => [
                ['front' => 'dharma', 'back' => 'duty'],
                ['front' => 'dharma', 'back' => 'duty'], // same shape, must be skipped
                ['front' => 'karma', 'back' => 'action'],
            ],
        ]);

        $result = LessonFlashCardsSync::sync($lesson);

        $this->assertSame(2, $result['cards']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(2, SrsCard::where('deck_id', $result['deck_id'])->count());
    }
}
