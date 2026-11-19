<?php

declare(strict_types=1);

namespace Tests\Feature\Srs;

use App\Models\SrsCard;
use App\Models\SrsDeck;
use Database\Seeders\SrsLigatureFrequencyDeckSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H6393 — колода «Лигатуры санскрита по частотности»: content-only
 * сеятель по паттерну H1280 (SrsRootFrequencyDeckSeeder). Канарейки:
 * порядок вставки = DCS-частотность (rank ASC → id ASC), чтение живёт
 * в `meaning` (ANSWER_KEYS), отдельного `iast` в полях НЕТ (иначе рендер
 * лица показывает devanagari+iast подряд и MC/typing становятся
 * тривиальными), близнецы-дестракторы указывают на реальные карточки.
 */
class SrsLigatureDeckTest extends TestCase
{
    use RefreshDatabase;

    private function seedLigatureDeck(): SrsDeck
    {
        (new SrsLigatureFrequencyDeckSeeder)->run();

        return SrsDeck::where('slug', 'sanskrit-ligatures-frequency')->firstOrFail();
    }

    /** @test */
    public function seeder_creates_system_deck_with_200_cards_in_frequency_order(): void
    {
        $deck = $this->seedLigatureDeck();

        $this->assertSame('system', $deck->visibility);
        $this->assertSame('sa', $deck->language);
        $this->assertSame(200, $deck->cards()->count());

        // Канарейка частотности: id-порядок совпадает с ранком DCS.
        $ranks = $deck->cards()
            ->orderBy('id')
            ->get()
            ->map(fn (SrsCard $c) => (int) $c->fields['rank'])
            ->values()
            ->all();
        $this->assertSame(range(1, 200), $ranks, 'cards must be inserted rank ASC so FSRS queueFor() serves the most frequent ligatures first');
    }

    /** @test */
    public function card_answer_is_the_reading_and_the_face_is_not_spoiled_by_an_iast_field(): void
    {
        $deck = $this->seedLigatureDeck();

        $top = $deck->cards()->get()->first(fn (SrsCard $c) => (int) $c->fields['rank'] === 1);
        $this->assertSame('प्र', $top->fields['devanagari']);
        $this->assertSame('pr', $top->fields['meaning'], 'meaning (ANSWER_KEYS) carries the IAST reading — the card answer');
        $this->assertSame('प + र', $top->fields['components']);
        $this->assertArrayNotHasKey('iast', $top->fields, 'iast must NOT be a card field: the review face renders devanagari+iast together, which would spoil MC/typing');

        // У каждой карточки ответ непустой (MC-варианты и typing работают на нём).
        $deck->cards()->get()->each(fn (SrsCard $c) => $this->assertNotSame('', trim((string) $c->fields['meaning'])));
    }

    /** @test */
    public function confusion_twins_point_at_real_deck_cards(): void
    {
        $deck = $this->seedLigatureDeck();

        $meanings = $deck->cards()->get()->map(fn (SrsCard $c) => (string) $c->fields['meaning'])->all();
        $twinned = 0;

        $deck->cards()->get()->each(function (SrsCard $c) use (&$twinned, $meanings): void {
            $confusion = trim((string) ($c->fields['confusion'] ?? ''));
            if ($confusion === '') {
                return;
            }
            $twinned++;
            foreach (explode(',', $confusion) as $partner) {
                $this->assertNotSame($c->fields['meaning'], $partner, 'a ligature must not be its own twin');
                $this->assertContains($partner, $meanings, "confusion partner [{$partner}] must resolve to a real card of the same deck (interim rule until the Kiseleva gold set lands, H6392)");
            }
        });

        $this->assertGreaterThan(0, $twinned, 'the interim deterministic rule is expected to yield confusion twins');
    }

    /** @test */
    public function seeder_is_idempotent_and_updates_in_place(): void
    {
        $this->seedLigatureDeck();
        $this->seedLigatureDeck();

        $this->assertSame(200, SrsDeck::where('slug', 'sanskrit-ligatures-frequency')->firstOrFail()->cards()->count());
    }

    /** @test */
    public function fixture_is_regenerable_and_wellformed(): void
    {
        $path = database_path('seeders/data/ligatures_frequency.tsv');
        $this->assertFileExists($path);
        $this->assertFileExists(database_path('seeders/data/build_ligatures_frequency_tsv.py'), 'the deterministic builder must stay committed next to the fixture');

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertSame("rank\tdevanagari\tiast\tcount\tpct\tcomponents\tconfusion", $lines[0]);

        $ranks = [];
        foreach (array_slice($lines, 1) as $line) {
            $cols = str_getcsv($line, "\t");
            $this->assertCount(7, $cols);
            $ranks[] = (int) $cols[0];
        }
        sort($ranks);
        $this->assertSame(range(1, 200), $ranks, 'fixture must carry exactly the DCS top-200');
    }
}
