<?php

declare(strict_types=1);

namespace App\Services\Srs;

use App\Models\Lesson;
use App\Models\SrsCard;
use App\Models\SrsDeck;
use App\Models\SrsNoteType;
use Illuminate\Support\Facades\DB;

/**
 * H1991 — Lesson.flash_cards (legacy JSON) -> lesson-tied SrsDeck/SrsCard
 * (K2 ruling, docs/PLAN_SYSTEMA_KOLODA_CONTENT_PIPELINE_2026H2.md). Single
 * sync path shared by the one-off `srs:migrate-lesson-flash-cards` command
 * and the `Lesson::saved()` hook, so a bulk migration and a later content
 * edit never diverge.
 *
 * Idempotent by wholesale replace, not content-matching: a lesson's deck
 * cards are deleted and reinserted from the current JSON on every sync,
 * inside a transaction. `flash_cards` entries carry no stable natural key
 * (heterogeneous shape — plain strings, {front,back}, {question,answer}, …),
 * so matching individual cards across runs would be guesswork; replace is
 * simple and provably duplicate-free. Deck is `visibility=private` — lesson
 * flashcards sit behind the same access as the lesson itself (paid courses),
 * so they must not surface on the public/system `/koloda` hub.
 */
class LessonFlashCardsSync
{
    public const NOTE_TYPE_KEY = 'lesson_flash';

    public const DECK_LANGUAGE = 'sa';

    /** @return array{deck_id:int,cards:int}|null null when the lesson has no flash cards */
    public static function sync(Lesson $lesson): ?array
    {
        if (! $lesson->hasFlashcards()) {
            return null;
        }

        return DB::transaction(function () use ($lesson) {
            $noteType = SrsNoteType::firstOrCreate(
                ['key' => self::NOTE_TYPE_KEY],
                [
                    'name' => 'Флешкарты урока',
                    'language' => self::DECK_LANGUAGE,
                    'fields' => ['front', 'back'],
                ],
            );

            $deck = SrsDeck::firstOrCreate(
                ['lesson_id' => $lesson->id],
                [
                    'note_type_id' => $noteType->id,
                    'name' => 'Флешкарты — '.$lesson->title,
                    'slug' => 'lesson-'.$lesson->id,
                    'language' => self::DECK_LANGUAGE,
                    'visibility' => 'private',
                    'description' => 'H1991: авто-синхронизировано из Lesson.flash_cards (dual-read, колонка сохранена).',
                ],
            );

            if ((int) $deck->note_type_id !== (int) $noteType->id) {
                $deck->note_type_id = $noteType->id;
                $deck->save();
            }

            SrsCard::where('deck_id', $deck->id)->delete();

            $cards = 0;
            foreach ((array) $lesson->flash_cards as $entry) {
                [$front, $back] = self::normalize($entry);
                if ($front === '') {
                    continue;
                }

                $fields = ['front' => $front];
                if ($back !== null && $back !== '') {
                    $fields['back'] = $back;
                }

                SrsCard::create([
                    'deck_id' => $deck->id,
                    'direction' => 'front_back',
                    'fields' => $fields,
                ]);
                $cards++;
            }

            return ['deck_id' => $deck->id, 'cards' => $cards];
        });
    }

    /**
     * Normalize one heterogeneous flash_cards entry into a front/back pair
     * (H1991 plan default: "shape unclear -> store raw pair as front/back
     * only"). Unrecognized array shapes are JSON-encoded into `front` whole
     * rather than dropped, so no source content is silently lost.
     *
     * @return array{0:string,1:?string}
     */
    private static function normalize(mixed $entry): array
    {
        if (is_string($entry) || is_numeric($entry)) {
            return [trim((string) $entry), null];
        }

        if (is_array($entry)) {
            foreach ([['front', 'back'], ['question', 'answer'], ['term', 'definition']] as [$fKey, $bKey]) {
                if (array_key_exists($fKey, $entry)) {
                    $front = trim((string) $entry[$fKey]);
                    $back = isset($entry[$bKey]) ? trim((string) $entry[$bKey]) : null;

                    return [$front, $back];
                }
            }

            if (array_is_list($entry) && $entry !== []) {
                $front = trim((string) $entry[0]);
                $back = isset($entry[1]) ? trim((string) $entry[1]) : null;

                return [$front, $back];
            }

            return [trim((string) json_encode($entry, JSON_UNESCAPED_UNICODE)), null];
        }

        return ['', null];
    }
}
