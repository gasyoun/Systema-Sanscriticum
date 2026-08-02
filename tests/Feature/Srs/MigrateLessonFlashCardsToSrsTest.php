<?php

declare(strict_types=1);

namespace Tests\Feature\Srs;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\SrsCard;
use App\Models\SrsDeck;
use App\Models\SrsNoteType;
use App\Models\User;
use App\Services\Srs\ReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * H1991 (K2) — `Lesson.flash_cards` JSON -> lesson-tied `SrsDeck`/`SrsCard`.
 * Covers the heterogeneous card shapes real lesson data has been written in
 * (bare strings from the n8n bridge, front/back pairs) per
 * docs/IMPLEMENTATION_SYSTEMA_KOLODA_CONTENT.md W1-D2 defaults.
 */
class MigrateLessonFlashCardsToSrsTest extends TestCase
{
    use RefreshDatabase;

    public function test_migrates_lesson_with_string_cards(): void
    {
        $lesson = Lesson::factory()->for(Course::factory())->create([
            'flash_cards' => ['карточка1', 'карточка2'],
        ]);

        Artisan::call('srs:migrate-lesson-flash-cards');

        $noteType = SrsNoteType::where('key', 'lesson_flash')->first();
        $this->assertNotNull($noteType);
        $this->assertSame('sa', $noteType->language);
        $this->assertSame(['front', 'back'], $noteType->fields);

        $deck = SrsDeck::where('lesson_id', $lesson->id)->first();
        $this->assertNotNull($deck);
        $this->assertSame('lesson-'.$lesson->id.'-flash', $deck->slug);
        $this->assertSame('private', $deck->visibility);
        $this->assertCount(2, $deck->cards);
        $this->assertSame('карточка1', $deck->cards[0]->fields['front']);
        $this->assertSame('', $deck->cards[0]->fields['back']);
    }

    public function test_migrates_lesson_with_front_back_pairs(): void
    {
        $lesson = Lesson::factory()->for(Course::factory())->create([
            'flash_cards' => [
                ['front' => 'satya', 'back' => 'истина'],
                ['front' => 'dharma', 'back' => 'закон'],
            ],
        ]);

        Artisan::call('srs:migrate-lesson-flash-cards');

        $deck = SrsDeck::where('lesson_id', $lesson->id)->firstOrFail();
        $card = SrsCard::where('deck_id', $deck->id)->where('fields->front', 'dharma')->firstOrFail();
        $this->assertSame('закон', $card->fields['back']);
    }

    public function test_lesson_without_flash_cards_is_skipped(): void
    {
        Lesson::factory()->for(Course::factory())->create(['flash_cards' => null]);
        Lesson::factory()->for(Course::factory())->create(['flash_cards' => []]);

        Artisan::call('srs:migrate-lesson-flash-cards');

        $this->assertSame(0, SrsDeck::count());
    }

    public function test_dry_run_writes_nothing(): void
    {
        Lesson::factory()->for(Course::factory())->create(['flash_cards' => ['a', 'b']]);

        Artisan::call('srs:migrate-lesson-flash-cards', ['--dry-run' => true]);

        $this->assertSame(0, SrsNoteType::count());
        $this->assertSame(0, SrsDeck::count());
        $this->assertSame(0, SrsCard::count());
    }

    public function test_is_idempotent_on_rerun(): void
    {
        Lesson::factory()->for(Course::factory())->create(['flash_cards' => ['a', 'b', 'c']]);

        Artisan::call('srs:migrate-lesson-flash-cards');
        Artisan::call('srs:migrate-lesson-flash-cards');

        $this->assertSame(1, SrsNoteType::where('key', 'lesson_flash')->count());
        $this->assertSame(1, SrsDeck::count());
        $this->assertSame(3, SrsCard::count());
    }

    public function test_flash_cards_column_is_not_dropped_or_cleared(): void
    {
        $lesson = Lesson::factory()->for(Course::factory())->create(['flash_cards' => ['a', 'b']]);

        Artisan::call('srs:migrate-lesson-flash-cards');

        $this->assertSame(['a', 'b'], $lesson->refresh()->flash_cards);
    }

    public function test_effective_flash_cards_prefers_srs_deck_once_migrated(): void
    {
        $lesson = Lesson::factory()->for(Course::factory())->create(['flash_cards' => ['legacy-only']]);

        $this->assertSame(
            [['front' => 'legacy-only', 'back' => '']],
            $lesson->effectiveFlashCards()
        );

        Artisan::call('srs:migrate-lesson-flash-cards');

        $migrated = $lesson->fresh()->effectiveFlashCards();
        $this->assertSame([['front' => 'legacy-only', 'back' => '']], $migrated);
    }

    /**
     * "Review queue works for subscribed user" (H1991 step 4) exercises the
     * FSRS queueing mechanism ({@see ReviewService})
     * against migrated cards directly. Lesson-tied decks are `private`
     * visibility (payment-gated lesson content, not a public/system catalog
     * entry) and are not yet wired into `SrsReview::canAccess()`'s
     * owner-only private-deck check — surfacing them through the lesson UI
     * (student.blade.php's flashcard button) rather than the generic
     * /koloda catalog is deferred past W1 (see ARCHITECTURE_SYSTEMA_KOLODA_
     * CONTENT.md § Lesson reconciliation, step 3 "dual-read window").
     */
    public function test_review_queue_serves_migrated_lesson_deck(): void
    {
        $user = User::factory()->create();
        Lesson::factory()->for(Course::factory())->create(['flash_cards' => ['satya - истина']]);

        Artisan::call('srs:migrate-lesson-flash-cards');

        $deck = SrsDeck::firstOrFail();
        $deck->subscribers()->attach($user->id, [
            'new_per_day' => 20,
            'direction' => 'front_back',
            'subscribed_at' => now(),
        ]);

        $queue = app(ReviewService::class)->queueFor($user, $deck);

        $this->assertCount(1, $queue);
        $this->assertSame('satya - истина', $queue->first()->fields['front']);
    }
}
