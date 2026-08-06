<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\SrsCard;
use App\Models\SrsDeck;
use App\Models\SrsNoteType;
use App\Models\User;

/**
 * H2111 — the ONE definition of the «Старт чтения» cohort SRS deck.
 *
 * Extracted from H2106's `srs:import-start-chteniya-cohort` command when the tap-token
 * panel gained its own add-to-SRS path: two writers (a bulk artisan import and a
 * per-token POST) must not each carry their own copy of the deck slug, the note-type
 * key, the field list and the dedupe key — that is how one deck silently becomes two,
 * or how the same lemma lands twice under two spellings of "already present".
 *
 * The deck is deliberately `private` with `user_id` set. `SrsController::cabinetIndex`
 * returns `system`/`public` decks to every logged-in user with no per-deck entitlement
 * check (its only gate is the global `srs.enabled`), so deck OWNERSHIP is the sole
 * mechanism that keeps cohort vocabulary off a non-buyer's /dvaram/koloda hub. Do not
 * "promote" it to a shared deck.
 *
 * Cards are keyed pack|lemma_slp1: one card per lemma per pack, so a student who taps a
 * word the bulk import already gave them is told it is already in the deck instead of
 * getting a second card for the same lemma.
 */
final class StartChteniyaSrsDeck
{
    public const DECK_SLUG = 'start-chteniya-cohort';

    public const NOTE_TYPE_KEY = 'start_chteniya_cohort';

    /** The note type's field order — also the exact key set every card's `fields` carries. */
    public const FIELDS = ['pack', 'lemma_slp1', 'surface', 'gloss_ru', 'gloss_en', 'locus'];

    public static function noteType(): SrsNoteType
    {
        return SrsNoteType::firstOrCreate(
            ['key' => self::NOTE_TYPE_KEY],
            [
                'name' => '«Старт чтения» — cohort lemmas',
                'language' => 'sa',
                'fields' => self::FIELDS,
            ],
        );
    }

    /** The student's own private cohort deck, created on first use. */
    public static function deckFor(User $user): SrsDeck
    {
        return SrsDeck::firstOrCreate(
            ['user_id' => $user->id, 'slug' => self::DECK_SLUG],
            [
                'note_type_id' => self::noteType()->id,
                'name' => '«Старт чтения» — словарь когорты',
                'language' => 'sa',
                'visibility' => 'private',
                'description' => 'H2106: lemmas from the cohort reading packs (Hitopadeśa-0), pinned by the H2109 freeze.',
            ],
        );
    }

    /**
     * Dedupe key — one card per lemma per pack.
     *
     * @param  array<string,mixed>  $fields
     */
    public static function cardKey(array $fields): string
    {
        return ($fields['pack'] ?? null).'|'.($fields['lemma_slp1'] ?? null);
    }

    /**
     * Every key already in the deck, as a set (`[key => true]`).
     *
     * @return array<string,true>
     */
    public static function existingKeys(SrsDeck $deck): array
    {
        $keys = [];
        foreach (SrsCard::query()->where('deck_id', $deck->id)->get(['fields']) as $card) {
            $keys[self::cardKey($card->fields ?? [])] = true;
        }

        return $keys;
    }

    /**
     * @param  array<string,mixed>  $fields
     */
    public static function createCard(SrsDeck $deck, array $fields): SrsCard
    {
        $normalized = [];
        foreach (self::FIELDS as $field) {
            $normalized[$field] = (string) ($fields[$field] ?? '');
        }

        return SrsCard::create([
            'deck_id' => $deck->id,
            'direction' => 'front_back',
            'source_word_id' => null,
            'fields' => $normalized,
        ]);
    }
}
