<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Lesson;
use App\Models\SrsCard;
use App\Models\SrsDeck;
use App\Models\SrsNoteType;
use Illuminate\Console\Command;

/**
 * H1991 (K2) — migrate `Lesson.flash_cards` JSON into lesson-tied
 * `SrsDeck`/`SrsCard` rows, one deck per lesson (slug `lesson-{id}-flash`,
 * `lesson_id` set, note type `lesson_flash`). The JSON column is dual-read,
 * not dropped, during the migration window (see
 * docs/ARCHITECTURE_SYSTEMA_KOLODA_CONTENT.md § Lesson reconciliation).
 *
 * `flash_cards` shapes are heterogeneous across lessons (the column has been
 * written to both by hand and by the n8n `LessonController::sync` bridge):
 * an associative `front`/`back` pair, a 2-element indexed pair, or a bare
 * string. Per the plan's own default ("shape heterogeneous -> store raw
 * pair as front/back only"), a bare string becomes `front` with an empty
 * `back` rather than being split guessed at.
 *
 * Idempotent: re-running upserts by (deck_id, fields->front, fields->back)
 * — matching {@see ImportBuhlerParadigmsSrsDeck}'s dedupe-on-content
 * pattern, since these cards have no `source_word_id` to key on.
 *
 *   php artisan srs:migrate-lesson-flash-cards
 *   php artisan srs:migrate-lesson-flash-cards --dry-run
 */
class MigrateLessonFlashCardsToSrs extends Command
{
    protected $signature = 'srs:migrate-lesson-flash-cards {--dry-run : Report counts only, write nothing}';

    protected $description = 'Migrate Lesson.flash_cards JSON into lesson-tied SrsDeck/SrsCard rows (dual-read; JSON column not dropped)';

    private const NOTE_TYPE_KEY = 'lesson_flash';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $lessons = Lesson::withFlashcards()->get();

        if ($lessons->isEmpty()) {
            $this->info('No lessons with non-empty flash_cards found.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $totalCards = 0;
            foreach ($lessons as $lesson) {
                $totalCards += count($this->normalizeCards($lesson->flash_cards));
            }
            $this->info("[dry-run] would touch {$lessons->count()} lesson(s), {$totalCards} card(s) total.");

            return self::SUCCESS;
        }

        $noteType = SrsNoteType::firstOrCreate(
            ['key' => self::NOTE_TYPE_KEY],
            [
                'name' => 'Флешкарты урока',
                'language' => 'sa',
                'fields' => ['front', 'back'],
            ],
        );

        $decksTouched = 0;
        $imported = 0;
        $existing = 0;

        foreach ($lessons as $lesson) {
            $deck = SrsDeck::firstOrCreate(
                ['lesson_id' => $lesson->id],
                [
                    'note_type_id' => $noteType->id,
                    'name' => 'Флешкарты — '.$lesson->title,
                    'slug' => 'lesson-'.$lesson->id.'-flash',
                    'language' => 'sa',
                    'visibility' => 'private',
                    'description' => 'H1991: смигрировано из Lesson.flash_cards (lesson_id='.$lesson->id.').',
                ],
            );
            $decksTouched++;

            foreach ($this->normalizeCards($lesson->flash_cards) as [$front, $back]) {
                $card = SrsCard::where('deck_id', $deck->id)
                    ->where('fields->front', $front)
                    ->where('fields->back', $back)
                    ->first();

                if ($card === null) {
                    SrsCard::create([
                        'deck_id' => $deck->id,
                        'direction' => 'front_back',
                        'fields' => ['front' => $front, 'back' => $back],
                    ]);
                    $imported++;
                } else {
                    $existing++;
                }
            }
        }

        $this->newLine();
        $this->info("Touched {$decksTouched} deck(s), imported {$imported} new card(s), {$existing} already present.");

        return self::SUCCESS;
    }

    /**
     * @return list<array{0:string,1:string}> list of [front, back] pairs
     */
    private function normalizeCards(mixed $flashCards): array
    {
        if (! is_array($flashCards)) {
            return [];
        }

        $pairs = [];
        foreach ($flashCards as $item) {
            if (is_string($item)) {
                $front = trim($item);
                $back = '';
            } elseif (is_array($item) && array_key_exists('front', $item)) {
                $front = trim((string) $item['front']);
                $back = trim((string) ($item['back'] ?? ''));
            } elseif (is_array($item) && array_is_list($item) && count($item) >= 1) {
                $front = trim((string) ($item[0] ?? ''));
                $back = trim((string) ($item[1] ?? ''));
            } else {
                continue;
            }

            if ($front === '') {
                continue;
            }

            $pairs[] = [$front, $back];
        }

        return $pairs;
    }
}
