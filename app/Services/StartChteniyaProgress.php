<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ActivityEvent;
use App\Models\SrsCard;
use App\Models\User;
use App\Support\StartChteniyaSrsDeck;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * H2107 — reading progress for a «Старт чтения» cohort student, and the
 * teacher-facing top stalled-lemma view.
 *
 * Reuses the two pipelines that already exist rather than building a third:
 * `activity_events` (via ActivityTracker::logEvent, event type
 * TYPE_READING_TOKEN_LOOKUP) for "which lemmas has this student looked up",
 * and the H2111 cohort SRS deck (StartChteniyaSrsDeck) for "which lemmas has
 * this student actually collected". No new table.
 */
final class StartChteniyaProgress
{
    /** How many of the pack's lemma-bearing tokens carry a lemma at all. */
    public static function lemmaBearingTokenCount(array $pack): int
    {
        $count = 0;
        foreach ($pack['sentences'] as $sentence) {
            foreach ($sentence['tokens'] as $token) {
                if (! empty($token['lemma'])) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /** Distinct lemma_slp1 values this student looked up in the given pack. */
    public static function lookedUpLemmasFor(User $user, string $packSlug): Collection
    {
        return DB::table('activity_events')
            ->where('user_id', $user->id)
            ->where('event_type', ActivityEvent::TYPE_READING_TOKEN_LOOKUP)
            ->get(['event_data'])
            ->map(fn ($row) => json_decode((string) $row->event_data, true) ?: [])
            ->filter(fn (array $data): bool => ($data['pack'] ?? null) === $packSlug)
            ->pluck('lemma_slp1')
            ->filter()
            ->unique();
    }

    /** Percent of the pack's lemma-bearing tokens the student has looked up (0-100). */
    public static function lookupPercentFor(User $user, array $pack): int
    {
        $total = self::lemmaBearingTokenCount($pack);
        if ($total === 0) {
            return 0;
        }

        $looked = self::lookedUpLemmasFor($user, (string) ($pack['slug'] ?? ''))->count();

        return (int) round(min($looked, $total) / $total * 100);
    }

    /** Unique lemmas the student has collected into their private cohort deck. */
    public static function uniqueLemmaCountFor(User $user): int
    {
        $deck = StartChteniyaSrsDeck::deckFor($user);

        return SrsCard::query()->where('deck_id', $deck->id)->count();
    }

    /**
     * Top-N lemmas students keep looking up without ever adding to their deck —
     * "stalled" = lookups minus how many of the looking-up students actually
     * collected the lemma. Aggregated straight off activity_events; cohort scale
     * (dozens of students, a handful of packs) makes an in-PHP aggregation fine.
     *
     * @return Collection<int, array{pack:string, lemma_slp1:string, surface:string, lookups:int, students:int, added:int, stalled_score:int}>
     */
    public static function topStalledLemmas(int $limit = 10): Collection
    {
        $byLemma = [];

        DB::table('activity_events')
            ->where('event_type', ActivityEvent::TYPE_READING_TOKEN_LOOKUP)
            ->orderBy('id')
            ->each(function ($row) use (&$byLemma): void {
                $data = json_decode((string) $row->event_data, true) ?: [];
                $lemma = $data['lemma_slp1'] ?? null;
                if (! $lemma || $row->user_id === null) {
                    return;
                }

                $pack = (string) ($data['pack'] ?? '');
                $key = $pack.'|'.$lemma;

                $byLemma[$key] ??= [
                    'pack' => $pack,
                    'lemma_slp1' => $lemma,
                    'surface' => (string) ($data['surface'] ?? $lemma),
                    'lookups' => 0,
                    'student_ids' => [],
                ];
                $byLemma[$key]['lookups']++;
                $byLemma[$key]['student_ids'][$row->user_id] = true;
            });

        return collect($byLemma)
            ->map(function (array $row): array {
                $studentIds = array_keys($row['student_ids']);
                $added = SrsCard::query()
                    ->whereIn('deck_id', function ($query) use ($studentIds): void {
                        $query->select('id')
                            ->from('srs_decks')
                            ->where('slug', StartChteniyaSrsDeck::DECK_SLUG)
                            ->whereIn('user_id', $studentIds);
                    })
                    ->get(['fields'])
                    ->filter(fn (SrsCard $card): bool => ($card->fields['lemma_slp1'] ?? null) === $row['lemma_slp1']
                        && ($card->fields['pack'] ?? null) === $row['pack'])
                    ->count();

                return [
                    'pack' => $row['pack'],
                    'lemma_slp1' => $row['lemma_slp1'],
                    'surface' => $row['surface'],
                    'lookups' => $row['lookups'],
                    'students' => count($studentIds),
                    'added' => $added,
                    'stalled_score' => $row['lookups'] - $added,
                ];
            })
            ->sortByDesc('stalled_score')
            ->values()
            ->take($limit);
    }
}
