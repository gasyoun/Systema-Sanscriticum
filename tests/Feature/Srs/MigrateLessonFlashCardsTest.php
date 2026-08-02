<?php

declare(strict_types=1);

namespace Tests\Feature\Srs;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\SrsCard;
use App\Models\SrsDeck;
use App\Models\SrsNoteType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * H1991 — Lesson.flash_cards JSON -> lesson-tied SrsDeck/SrsCard, K2 ruling
 * (docs/PLAN_SYSTEMA_KOLODA_CONTENT_PIPELINE_2026H2.md). Column stays
 * (dual-read); LessonFlashCardsSync is exercised both via the artisan
 * command and via the Lesson::saved() hook.
 */
class MigrateLessonFlashCardsTest extends TestCase
{
    use RefreshDatabase;

    private function makeLesson(array $flashCards, array $attrs = []): Lesson
    {
        $course = Course::factory()->create();

        return Lesson::factory()->for($course)->create(array_merge(
            ['flash_cards' => $flashCards],
            $attrs,
        ));
    }

    public function test_migrates_plain_string_entries_into_front_only_cards(): void
    {
        $lesson = $this->makeLesson(['dharma — долг', 'karma — деяние']);

        Artisan::call('srs:migrate-lesson-flash-cards');

        $noteType = SrsNoteType::where('key', 'lesson_flash')->first();
        $this->assertNotNull($noteType);
        $this->assertSame('sa', $noteType->language);

        $deck = SrsDeck::where('lesson_id', $lesson->id)->first();
        $this->assertNotNull($deck);
        $this->assertSame('lesson-'.$lesson->id, $deck->slug);
        $this->assertSame('private', $deck->visibility);
        $this->assertSame($noteType->id, $deck->note_type_id);
        $this->assertCount(2, $deck->cards);

        $fronts = $deck->cards->pluck('fields.front')->all();
        $this->assertSame(['dharma — долг', 'karma — деяние'], $fronts);
        $this->assertArrayNotHasKey('back', $deck->cards->first()->fields);
    }

    public function test_migrates_front_back_pair_objects(): void
    {
        $lesson = $this->makeLesson([
            ['front' => 'गुरु', 'back' => 'учитель'],
            ['question' => 'śiṣya', 'answer' => 'ученик'],
            ['term' => 'ज्ञान', 'definition' => 'знание'],
            ['राम', 'имя героя'],
        ]);

        Artisan::call('srs:migrate-lesson-flash-cards');

        $deck = SrsDeck::where('lesson_id', $lesson->id)->firstOrFail();
        $this->assertCount(4, $deck->cards);

        $pairs = $deck->cards->map(fn (SrsCard $c) => [$c->fields['front'], $c->fields['back'] ?? null])->all();
        $this->assertSame([
            ['गुरु', 'учитель'],
            ['śiṣya', 'ученик'],
            ['ज्ञान', 'знание'],
            ['राम', 'имя героя'],
        ], $pairs);
    }

    public function test_lessons_without_flash_cards_are_skipped(): void
    {
        $this->makeLesson([]);
        $this->makeLesson([], ['flash_cards' => null]);

        Artisan::call('srs:migrate-lesson-flash-cards');

        $this->assertSame(0, SrsDeck::count());
        $this->assertSame(0, SrsNoteType::count());
    }

    public function test_is_idempotent_on_rerun_no_duplicate_decks_or_cards(): void
    {
        $lesson = $this->makeLesson(['one', 'two', 'three']);

        Artisan::call('srs:migrate-lesson-flash-cards');
        Artisan::call('srs:migrate-lesson-flash-cards');

        $this->assertSame(1, SrsNoteType::where('key', 'lesson_flash')->count());
        $this->assertSame(1, SrsDeck::where('lesson_id', $lesson->id)->count());
        $this->assertSame(3, SrsCard::count());
    }

    public function test_dry_run_writes_nothing(): void
    {
        // Lesson::saved() already syncs a deck on creation (below) — dry-run's
        // job is to add nothing ON TOP of that, so compare counts before/after
        // rather than asserting zero.
        $this->makeLesson(['one', 'two']);
        $before = [SrsNoteType::count(), SrsDeck::count(), SrsCard::count()];

        Artisan::call('srs:migrate-lesson-flash-cards', ['--dry-run' => true]);

        $this->assertSame($before, [SrsNoteType::count(), SrsDeck::count(), SrsCard::count()]);
    }

    public function test_saving_flash_cards_auto_syncs_deck_via_model_hook(): void
    {
        $lesson = $this->makeLesson(['auto-synced']);

        $deck = SrsDeck::where('lesson_id', $lesson->id)->first();
        $this->assertNotNull($deck, 'Lesson::saved() should sync a deck without running the artisan command');
        $this->assertCount(1, $deck->cards);
    }

    public function test_resync_replaces_cards_wholesale_on_content_change(): void
    {
        $lesson = $this->makeLesson(['old-card-a', 'old-card-b']);
        $deckId = SrsDeck::where('lesson_id', $lesson->id)->firstOrFail()->id;
        $this->assertSame(2, SrsCard::where('deck_id', $deckId)->count());

        $lesson->update(['flash_cards' => ['new-card']]);

        $this->assertSame(1, SrsDeck::where('lesson_id', $lesson->id)->count());
        $cards = SrsCard::where('deck_id', $deckId)->get();
        $this->assertCount(1, $cards);
        $this->assertSame('new-card', $cards->first()->fields['front']);
    }

    public function test_flashcards_for_display_prefers_srs_when_deck_exists(): void
    {
        $lesson = $this->makeLesson(['front-only']);

        $display = $lesson->fresh()->flashcardsForDisplay();

        $this->assertSame([['front' => 'front-only', 'back' => null]], $display);
    }

    public function test_flashcards_for_display_falls_back_to_raw_json_without_deck(): void
    {
        $course = Course::factory()->create();
        $lesson = Lesson::factory()->for($course)->make(['flash_cards' => ['legacy-only']]);
        // Bypass the saved() hook entirely by not persisting — dual-read
        // fallback must still work for an in-memory/unsynced lesson.
        $lesson->id = 999999;

        $display = $lesson->flashcardsForDisplay();

        $this->assertSame([['front' => 'legacy-only', 'back' => null]], $display);
    }
}
