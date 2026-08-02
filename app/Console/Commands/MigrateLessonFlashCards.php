<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Lesson;
use App\Services\Srs\LessonFlashCardsSync;
use Illuminate\Console\Command;

/**
 * H1991 — repeatable migration: Lesson.flash_cards JSON -> lesson-tied
 * SrsDeck/SrsCard (K2 ruling, docs/PLAN_SYSTEMA_KOLODA_CONTENT_PIPELINE_2026H2.md).
 * The `flash_cards` column is NOT dropped (dual-read window — see
 * {@see Lesson::flashcardsForDisplay()}). {@see LessonFlashCardsSync}
 * is the shared sync path also wired into `Lesson::saved()`, so re-running this
 * command after a later content edit is safe and idempotent (wholesale replace
 * per lesson, never additive).
 *
 *   php artisan srs:migrate-lesson-flash-cards --dry-run
 *   php artisan srs:migrate-lesson-flash-cards
 */
class MigrateLessonFlashCards extends Command
{
    protected $signature = 'srs:migrate-lesson-flash-cards
                            {--dry-run : Report the census only, write nothing}';

    protected $description = 'Migrate Lesson.flash_cards JSON into lesson-tied SrsDeck/SrsCard (dual-read, H1991)';

    public function handle(): int
    {
        $lessons = Lesson::query()->withFlashcards()->get();

        $this->info("Census: {$lessons->count()} lesson(s) with non-empty flash_cards");

        if ((bool) $this->option('dry-run')) {
            $rawEntries = $lessons->sum(fn (Lesson $lesson) => is_array($lesson->flash_cards) ? count($lesson->flash_cards) : 0);
            $this->info("[dry-run] would sync {$lessons->count()} deck(s) from {$rawEntries} raw flash_cards entrie(s); no writes made.");

            return self::SUCCESS;
        }

        $decks = 0;
        $cards = 0;

        foreach ($lessons as $lesson) {
            $result = LessonFlashCardsSync::sync($lesson);
            if ($result === null) {
                continue;
            }

            $decks++;
            $cards += $result['cards'];
        }

        $this->newLine();
        $this->info("Synced {$decks} deck(s), {$cards} card(s).");

        return self::SUCCESS;
    }
}
