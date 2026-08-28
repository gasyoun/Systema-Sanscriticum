<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\SrsDeck;
use App\Models\SrsNoteType;
use App\Models\User;

/**
 * H3643 — persistent private SRS deck seeded on guest /register.
 *
 * Same ownership shape as {@see StartChteniyaSrsDeck}: visibility=private and
 * user_id set. The cabinet SRS hub lists system/public decks to every logged-in
 * user, so a shared "club free" deck would leak onto people who never signed up
 * here. Cards are not invented — the deck exists so later club/onboarding
 * imports have a stable home.
 */
final class ClubFreeTierSrsDeck
{
    public const DECK_SLUG = 'club-free-tier';

    public const NOTE_TYPE_KEY = 'club_free_tier';

    /** @var list<string> */
    public const FIELDS = ['iast', 'translation_ru'];

    public static function noteType(): SrsNoteType
    {
        return SrsNoteType::firstOrCreate(
            ['key' => self::NOTE_TYPE_KEY],
            [
                'name' => 'Клуб — свободный уровень',
                'language' => 'sa',
                'fields' => self::FIELDS,
            ],
        );
    }

    public static function deckFor(User $user): SrsDeck
    {
        return SrsDeck::firstOrCreate(
            ['user_id' => $user->id, 'slug' => self::DECK_SLUG],
            [
                'note_type_id' => self::noteType()->id,
                'name' => 'Свободный уровень — колода',
                'language' => 'sa',
                'visibility' => 'private',
                'description' => 'H3643: persistent SRS deck seeded on guest /register (Free-tier).',
            ],
        );
    }
}
