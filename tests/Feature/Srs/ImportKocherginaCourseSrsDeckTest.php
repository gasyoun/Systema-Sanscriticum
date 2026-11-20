<?php

declare(strict_types=1);

namespace Tests\Feature\Srs;

use App\Livewire\SrsReview;
use App\Models\SrsCard;
use App\Models\SrsDeck;
use App\Models\SrsNoteType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * H8211 — full-course Kochergina import: 41 export levels = 1 intro level
 * (the textbook's list of grammatical abbreviations) + Занятия I..XL.
 * Runs against the real production export in
 * database/seeders/data/memrise_6502608/, not a trimmed fixture: the
 * 41/40 mapping, per-level count parity and the duplicate-headword
 * preservation are the whole point of the handoff.
 */
class ImportKocherginaCourseSrsDeckTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<array{iast:string,translation_ru:string}> */
    private function readSourceRows(int $level): array
    {
        $path = base_path(sprintf('database/seeders/data/memrise_6502608/level_%02d.csv', $level));
        $handle = fopen($path, 'rb');
        $this->assertSame(['col_a', 'col_b'], fgetcsv($handle, 0, ',', '"', ''));

        $rows = [];
        while (($raw = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $iast = trim((string) ($raw[0] ?? ''));
            $gloss = trim((string) ($raw[1] ?? ''));
            if ($iast === '') {
                continue;
            }
            $rows[] = ['iast' => $iast, 'translation_ru' => $gloss];
        }
        fclose($handle);

        return $rows;
    }

    public function test_maps_41_levels_to_intro_plus_40_lesson_decks(): void
    {
        Artisan::call('srs:import-kochergina-course');

        $this->assertSame(41, SrsDeck::count());
        $this->assertSame(0, SrsDeck::where('slug', 'like', 'memrise-6502608-%')->count());

        $intro = SrsDeck::where('slug', 'kochergina-intro')->firstOrFail();
        $this->assertSame('Кочергина — условные сокращения', $intro->name);
        $this->assertSame('system', $intro->visibility);
        $this->assertSame('sa', $intro->language);

        $lesson1 = SrsDeck::where('slug', 'kochergina-lesson-1')->firstOrFail();
        $this->assertSame('Кочергина — Занятие I (словарь)', $lesson1->name);

        $lesson40 = SrsDeck::where('slug', 'kochergina-lesson-40')->firstOrFail();
        $this->assertSame('Кочергина — Занятие XL (словарь)', $lesson40->name);

        foreach (SrsDeck::where('slug', 'like', 'kochergina-lesson-%')->get() as $deck) {
            $this->assertSame('system', $deck->visibility, $deck->slug);
        }
    }

    public function test_deck_card_counts_match_source_csv_rows_for_every_level(): void
    {
        Artisan::call('srs:import-kochergina-course');

        foreach (range(1, 41) as $level) {
            $slug = $level === 1 ? 'kochergina-intro' : 'kochergina-lesson-'.($level - 1);
            $deck = SrsDeck::where('slug', $slug)->firstOrFail();
            $expected = count($this->readSourceRows($level));

            $this->assertSame(
                $expected,
                SrsCard::where('deck_id', $deck->id)->count(),
                "level {$level} -> {$slug}: deck card count must match source row count",
            );
        }

        $this->assertSame(2124, SrsCard::count());
    }

    public function test_canary_card_contents_match_source_first_middle_last(): void
    {
        Artisan::call('srs:import-kochergina-course');

        $intro = SrsDeck::where('slug', 'kochergina-intro')->firstOrFail();
        $this->assertSame(
            ['iast' => 'A.', 'translation_ru' => 'винительный падеж'],
            SrsCard::where('deck_id', $intro->id)->orderBy('id')->first()->fields,
        );

        $lesson1 = SrsDeck::where('slug', 'kochergina-lesson-1')->firstOrFail();
        $this->assertSame(
            ['iast' => 'ka', 'translation_ru' => 'кто, который?'],
            SrsCard::where('deck_id', $lesson1->id)->orderBy('id')->first()->fields,
        );

        $lesson20 = SrsDeck::where('slug', 'kochergina-lesson-20')->firstOrFail();
        $this->assertSame(
            ['iast' => 'śrī', 'translation_ru' => 'славный, знаменитый, известный'],
            SrsCard::where('deck_id', $lesson20->id)->orderBy('id')->first()->fields,
        );

        $lesson40 = SrsDeck::where('slug', 'kochergina-lesson-40')->firstOrFail();
        $this->assertSame(
            ['iast' => 'āgamana', 'translation_ru' => '(n) приход', 'notes' => '(n)'],
            SrsCard::where('deck_id', $lesson40->id)->orderBy('id')->first()->fields,
        );
    }

    public function test_conflicting_duplicate_headword_rows_are_kept_as_distinct_cards(): void
    {
        Artisan::call('srs:import-kochergina-course');

        // Level 24 (Занятие XXIII) has `labh` twice with conflicting glosses —
        // a source_word_id identity would collapse the second variant away.
        $lesson23 = SrsDeck::where('slug', 'kochergina-lesson-23')->firstOrFail();
        $labh = SrsCard::where('deck_id', $lesson23->id)->where('fields->iast', 'labh')->get();

        $this->assertCount(2, $labh);
        $this->assertSame(
            ['получать', 'получать, брать, добиваться чего либо'],
            $labh->pluck('fields.translation_ru')->sort()->values()->all(),
        );
        $this->assertSame(44, SrsCard::where('deck_id', $lesson23->id)->count());
    }

    public function test_is_idempotent_on_rerun(): void
    {
        Artisan::call('srs:import-kochergina-course');
        Artisan::call('srs:import-kochergina-course');

        $this->assertSame(41, SrsDeck::count());
        $this->assertSame(2124, SrsCard::count());
        $this->assertSame(1, SrsNoteType::where('key', 'kochergina_course')->count());
    }

    public function test_reuses_existing_h1431_lesson1_deck_without_duplicating(): void
    {
        // The production state: lesson 1 already imported via H1431.
        Artisan::call('srs:import-kochergina-lesson1', [
            '--path' => base_path('database/seeders/data/memrise_6502608/level_02.csv'),
        ]);

        Artisan::call('srs:import-kochergina-course');

        $this->assertSame(1, SrsDeck::where('slug', 'kochergina-lesson-1')->count());

        $h1431NoteType = SrsNoteType::where('key', 'kochergina_l1')->firstOrFail();
        $lesson1 = SrsDeck::where('slug', 'kochergina-lesson-1')->firstOrFail();
        $this->assertSame($h1431NoteType->id, $lesson1->note_type_id);
        $this->assertSame(41, SrsCard::where('deck_id', $lesson1->id)->count());

        $this->assertSame(41, SrsDeck::count());
        $this->assertSame(2124, SrsCard::count());
    }

    public function test_dry_run_writes_nothing(): void
    {
        Artisan::call('srs:import-kochergina-course', ['--dry-run' => true]);

        $this->assertSame(0, SrsDeck::count());
        $this->assertSame(0, SrsCard::count());
        $this->assertSame(0, SrsNoteType::count());
    }

    public function test_missing_manifest_fails_cleanly(): void
    {
        $exit = Artisan::call('srs:import-kochergina-course', [
            '--path' => sys_get_temp_dir().'/kochergina-no-such-export',
        ]);

        $this->assertSame(1, $exit);
        $this->assertSame(0, SrsDeck::count());
    }

    /**
     * Student canary (H8211): a guest opens the first, middle and last lesson
     * on the public URLs and starts a review — no payment, no course rights.
     */
    public function test_guest_canary_opens_and_reviews_first_middle_last_lesson(): void
    {
        config(['srs.enabled' => true]);

        Artisan::call('srs:import-kochergina-course');

        $this->get('/koloda')
            ->assertOk()
            ->assertSee('Кочергина — Занятие I (словарь)')
            ->assertSee('Кочергина — Занятие XX (словарь)')
            ->assertSee('Кочергина — Занятие XL (словарь)');

        $this->get('/koloda/kochergina-lesson-1')->assertOk()->assertSee('Кочергина — Занятие I (словарь)');
        $this->get('/koloda/kochergina-lesson-20')->assertOk()->assertSee('Кочергина — Занятие XX (словарь)');
        $this->get('/koloda/kochergina-lesson-40')->assertOk()->assertSee('Кочергина — Занятие XL (словарь)');

        // Guest trial starts a review without persisting progress states.
        Livewire::test(SrsReview::class, ['slug' => 'kochergina-lesson-40'])
            ->assertSet('isGuest', true)
            ->call('reveal')
            ->call('grade', 3)
            ->assertSet('guestGraded', 1);

        $this->assertDatabaseCount('srs_review_states', 0);
        $this->assertDatabaseCount('srs_review_logs', 0);
    }
}
