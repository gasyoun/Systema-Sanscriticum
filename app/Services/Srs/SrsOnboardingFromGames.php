<?php

declare(strict_types=1);

namespace App\Services\Srs;

use App\Models\Dictionary;
use App\Models\DictionaryWord;
use App\Models\GameEvent;
use App\Models\SrsCard;
use App\Models\SrsDeck;
use App\Models\SrsNoteType;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * H1680 — Wave 2: register→SRS onboarding. On first authenticated request
 * after free play, imports up to CAP distinct lemmas the SAME browser saw
 * as a guest — matched by the existing `anon_id` (never a `user_id` column
 * on game_events, see GameTelemetryController / the create migration and
 * GamesFunnelTelemetryTest::the_table_stores_no_ip_or_user_agent_by_design) —
 * into the shared system SrsDeck (slug DECK_SLUG), then subscribes the
 * now-authenticated user to it.
 *
 * Idempotent: firstOrCreate on dictionary/note-type/deck/word/card, so a
 * second call for the same anon_id (or a different anon_id whose guest
 * played the same lemmas) creates no duplicate rows — only the subscription
 * pivot is (re)synced.
 *
 * Behind config('srs.enabled') — mirrors ImportKoshaSrsDeckB1Demo's
 * flag-off no-op: while OFF, this writes nothing and the deck stays
 * invisible (R-6, config/srs.php).
 */
class SrsOnboardingFromGames
{
    public const DECK_SLUG = 'onboarding-from-games';

    public const CAP = 20;

    public function importForUser(User $user, ?string $anonId): int
    {
        if (! config('srs.enabled') || blank($anonId)) {
            return 0;
        }

        $lemmas = $this->seenLemmas($anonId);
        if ($lemmas === []) {
            return 0; // guest never played (or cleared localStorage) -> no-op
        }

        $dictionary = Dictionary::firstOrCreate(
            ['name' => 'Онбординг из тренажёров'],
            [
                'description' => 'H1680: слова, увиденные в бесплатных тренажёрах /lila до регистрации.',
                'is_active' => true,
            ],
        );

        $noteType = SrsNoteType::firstOrCreate(
            ['key' => 'games_onboarding'],
            ['name' => 'Тренажёры — онбординг', 'language' => 'sa', 'fields' => ['iast', 'translation_ru']],
        );

        $deck = SrsDeck::firstOrCreate(
            ['slug' => self::DECK_SLUG, 'user_id' => null],
            [
                'note_type_id' => $noteType->id,
                'name' => 'Слова из тренажёров',
                'language' => 'sa',
                'visibility' => 'system',
                'description' => 'H1680: слова из бесплатных тренажёров /lila, увиденные до регистрации — стартовая колода.',
            ],
        );

        foreach ($lemmas as $lemma) {
            $word = DictionaryWord::firstOrCreate(
                ['dictionary_id' => $dictionary->id, 'iast' => $lemma['iast']],
                ['translation' => $lemma['ru'], 'slug' => 'games-onboarding-'.Str::slug($lemma['iast'])],
            );

            SrsCard::firstOrCreate(
                ['deck_id' => $deck->id, 'source_word_id' => $word->id],
                ['direction' => 'front_back', 'fields' => ['iast' => $lemma['iast'], 'translation_ru' => $lemma['ru']]],
            );
        }

        $deck->subscribers()->syncWithoutDetaching([
            $user->id => ['subscribed_at' => now()],
        ]);

        return count($lemmas);
    }

    /**
     * Distinct lemmas from this anon_id's `item_seen` events, oldest-first,
     * capped at CAP. Reads only game_events.payload — never user_id/guest_id.
     *
     * @return list<array{iast:string, ru:string}>
     */
    private function seenLemmas(string $anonId): array
    {
        $seen = [];

        GameEvent::query()
            ->where('anon_id', $anonId)
            ->where('event', GameEvent::ITEM_SEEN)
            ->whereNotNull('payload')
            ->orderBy('created_at')
            ->get(['payload'])
            ->each(function (GameEvent $event) use (&$seen): void {
                foreach ((array) ($event->payload['items'] ?? []) as $item) {
                    $iast = (string) ($item['iast'] ?? '');
                    if ($iast === '' || isset($seen[$iast])) {
                        continue;
                    }
                    $seen[$iast] = ['iast' => $iast, 'ru' => (string) ($item['ru'] ?? '')];
                }
            });

        return array_slice(array_values($seen), 0, self::CAP);
    }
}
