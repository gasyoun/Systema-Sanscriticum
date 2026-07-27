<?php

declare(strict_types=1);

namespace App\Services\Games;

use App\Console\Commands\ImportKocherginaLesson1SrsDeck;
use App\Models\GameEvent;
use App\Models\SrsCard;
use App\Models\SrsDeck;
use App\Models\SrsNoteType;
use App\Models\User;
use Database\Seeders\SrsRootFrequencyDeckSeeder;

/**
 * H1680 — Wave 2 SRS onboarding: "free games -> register -> seed an SRS
 * deck from what you actually played" (PLAN D8/D10, ARCHITECTURE §2.3).
 *
 * Deliberately does NOT invent new vocabulary data. It only copies cards
 * out of EXISTING canonical system decks that already cover a completed
 * pack's content:
 *   - `match/kochergina-l1`            -> system deck `kochergina-lesson-1`
 *     (H1431, {@see ImportKocherginaLesson1SrsDeck})
 *   - `roots/top-25|top-50|top-100`    -> system deck `sanskrit-roots-frequency`
 *     (H1280, {@see SrsRootFrequencyDeckSeeder}), first N
 *     cards by id (id order == frequency-rank order, per that seeder's own
 *     documented invariant)
 *
 * Packs with no single-lemma vocabulary content (phonology drills like
 * sort/vowel-length, transliteration-only match/iast-cyrillic, sentence-pair
 * match/ru-sa-*, grammar cloze/table/sort drills) are intentionally absent
 * from PACKS and silently contribute nothing — that is correct, not a bug.
 * match/verb-roots has real vocabulary (11 inline pairs) but no backing
 * canonical deck to copy from; left out rather than inventing a fourth
 * parallel content store — a known, logged gap (see H1680 in
 * Systema-Sanscriticum's .ai_state.md Dev Notes).
 *
 * Which (drill,band) pairs a user actually finished comes from `game_events`
 * `user_id` (H1680) — set on write when authenticated, and backfilled onto
 * prior anonymous rows by {@see mergeGuestEvents()} on first login.
 */
class GamesOnboardingImporter
{
    public const DECK_SLUG = 'onboarding-from-games';

    private const CARD_CAP = 20;

    /** @var array<string, array{deck_slug:string, take:int}> */
    private const PACKS = [
        'match/kochergina-l1' => ['deck_slug' => 'kochergina-lesson-1', 'take' => 25],
        'roots/top-25' => ['deck_slug' => 'sanskrit-roots-frequency', 'take' => 25],
        'roots/top-50' => ['deck_slug' => 'sanskrit-roots-frequency', 'take' => 50],
        'roots/top-100' => ['deck_slug' => 'sanskrit-roots-frequency', 'take' => 100],
    ];

    /**
     * Links prior anonymous `game_events` rows to $user by the SAME anon_id
     * their browser already carries in localStorage (login doesn't clear
     * it). Safe to call with a null/empty anon_id (no-op) or repeatedly
     * (WHERE user_id IS NULL means an already-merged row is never touched
     * twice).
     */
    public function mergeGuestEvents(User $user, ?string $anonId): void
    {
        if ($anonId === null || $anonId === '') {
            return;
        }

        GameEvent::query()
            ->where('anon_id', $anonId)
            ->whereNull('user_id')
            ->update(['user_id' => $user->id]);
    }

    /**
     * Imports up to CARD_CAP cards total into the user's private
     * `onboarding-from-games` deck, from whichever completed packs map to a
     * known canonical deck. Idempotent: safe to call again later (e.g. the
     * user plays more free games after registering) — existing cards are
     * never duplicated (keyed by deck_id + fields->iast) and the cap is
     * enforced across calls via the deck's current card count, never just
     * this call's own additions.
     *
     * @return int number of newly created cards
     */
    public function importForUser(User $user): int
    {
        $completedPacks = GameEvent::query()
            ->where('user_id', $user->id)
            ->where('event', GameEvent::COMPLETE)
            ->whereNotNull('drill')
            ->orderBy('created_at')
            ->get(['drill', 'band', 'created_at'])
            ->unique(fn (GameEvent $e): string => $e->drill.'/'.($e->band ?? ''))
            ->values();

        if ($completedPacks->isEmpty()) {
            return 0;
        }

        $budget = self::CARD_CAP - $this->existingCardCount($user);
        if ($budget <= 0) {
            return 0;
        }

        $imported = 0;
        $deck = null;

        foreach ($completedPacks as $event) {
            if ($budget <= 0) {
                break;
            }

            $mapping = self::PACKS[$event->drill.'/'.$event->band] ?? null;
            if ($mapping === null) {
                continue;
            }

            $sourceDeck = SrsDeck::query()
                ->where('slug', $mapping['deck_slug'])
                ->whereNull('user_id')
                ->first();
            if ($sourceDeck === null) {
                continue; // defensive: canonical deck not seeded in this environment
            }

            $sourceCards = $sourceDeck->cards()->orderBy('id')->limit($mapping['take'])->get();

            foreach ($sourceCards as $sourceCard) {
                if ($budget <= 0) {
                    break;
                }

                $iast = $sourceCard->fields['iast'] ?? null;
                if (! is_string($iast) || $iast === '') {
                    continue;
                }

                $deck ??= $this->deckFor($user);

                $card = SrsCard::firstOrCreate(
                    ['deck_id' => $deck->id, 'fields->iast' => $iast],
                    [
                        'direction' => 'front_back',
                        'source_word_id' => $sourceCard->source_word_id,
                        'fields' => [
                            'devanagari' => $sourceCard->fields['devanagari'] ?? null,
                            'iast' => $iast,
                            'translation' => $sourceCard->fields['translation_ru']
                                ?? $sourceCard->fields['gloss_ru']
                                ?? $sourceCard->fields['translation']
                                ?? '',
                            'source_deck' => $mapping['deck_slug'],
                        ],
                    ],
                );

                if ($card->wasRecentlyCreated) {
                    $imported++;
                    $budget--;
                }
            }
        }

        return $imported;
    }

    private function existingCardCount(User $user): int
    {
        $deck = SrsDeck::query()->where('user_id', $user->id)->where('slug', self::DECK_SLUG)->first();

        return $deck?->cards()->count() ?? 0;
    }

    private function deckFor(User $user): SrsDeck
    {
        $noteType = SrsNoteType::firstOrCreate(
            ['key' => 'games_onboarding'],
            [
                'name' => 'Санскрит — из бесплатных игр',
                'language' => 'sa',
                'fields' => ['devanagari', 'iast', 'translation'],
            ],
        );

        return SrsDeck::firstOrCreate(
            ['user_id' => $user->id, 'slug' => self::DECK_SLUG],
            [
                'note_type_id' => $noteType->id,
                'name' => 'Слова из бесплатных игр',
                'language' => 'sa',
                'visibility' => 'private',
                'description' => 'H1680: слова из завершённых бесплатных тренажёров /lila '
                    .'(до регистрации), скопированные из системных колод по паку — '
                    .'не более '.self::CARD_CAP.' карточек.',
            ],
        );
    }
}
