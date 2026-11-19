<?php

declare(strict_types=1);

namespace Tests\Feature\Srs;

use App\Models\SrsCard;
use App\Models\SrsDeck;
use App\Models\SrsNoteType;
use App\Models\SrsReviewState;
use App\Models\User;
use App\Services\Srs\MegaDeckSegmenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H7966 (трек А, Q18) — own-data canary сегментации мега-деков:
 *
 *  ИНВАРИАНТ: после MegaDeckSegmenter::segment() множество srs_cards.id и
 *  каждая строка srs_review_states НЕ меняются — двигается только
 *  srs_cards.deck_id. Это док-во безопасности 23k-переезда на проде.
 *
 * Плюс: корректность бандлов (топ-500 / 500-2000 / остальное), наследование
 * таксономии подколодами и идемпотентность повторного запуска.
 */
class SrsMegaDeckSegmentationTest extends TestCase
{
    use RefreshDatabase;

    private MegaDeckSegmenter $segmenter;

    protected function setUp(): void
    {
        parent::setUp();
        config(['srs.enabled' => true]);
        $this->segmenter = new MegaDeckSegmenter;
    }

    /** @return array{deck: SrsDeck, cards: array<int, int>} card_id по бандлу ожидания */
    private function makeMegaDeck(): array
    {
        $noteType = SrsNoteType::firstOrCreate(
            ['key' => 'sanskrit_basic'],
            [
                'name' => 'Санскрит — базовая',
                'language' => 'sa',
                'fields' => ['devanagari', 'iast', 'cyrillic', 'translation'],
            ],
        );

        $deck = SrsDeck::create([
            'note_type_id' => $noteType->id,
            'name' => 'Санскрит — ядро словаря',
            'slug' => 'sanskrit-core',
            'language' => 'sa',
            'visibility' => 'system',
            'topic' => 'lexicon',
            'difficulty' => 'intermediate',
        ]);

        // slp1-ключи после IastToSlp1::keyFor: aaa / bbb / ccc; 'zzz' нет в карте.
        $plan = array_merge(
            array_fill(0, 3, ['iast' => 'aaa']),   // rank 10   → top500
            array_fill(0, 3, ['iast' => 'bbb']),   // rank 700  → 500-2000
            array_fill(0, 3, ['iast' => 'ccc']),   // rank 3000 → rest
            array_fill(0, 2, ['iast' => 'zzz']),   // нет ранга → rest
            [['translation' => 'без iast']],        // нет ключа → rest
        );

        $ids = [];
        foreach ($plan as $fields) {
            $card = SrsCard::create([
                'deck_id' => $deck->id,
                'fields' => $fields + ['translation' => 'глосса'],
            ]);
            $ids[] = (int) $card->id;
        }

        return ['deck' => $deck, 'cards' => $ids];
    }

    public function test_segmentation_preserves_card_ids_and_review_states(): void
    {
        ['deck' => $deck, 'cards' => $cardIds] = $this->makeMegaDeck();

        $user = User::factory()->create();
        foreach (array_slice($cardIds, 0, 5) as $cardId) {
            SrsReviewState::create(['user_id' => $user->id, 'card_id' => $cardId]);
        }

        $cardIdsBefore = SrsCard::query()->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $statesBefore = SrsReviewState::query()->pluck('card_id')->sort()->values()->all();
        $stateCountBefore = SrsReviewState::query()->count();

        $moved = $this->segmenter->segment($deck, ['aaa' => 10, 'bbb' => 700, 'ccc' => 3000]);

        // 1. Идентичность карточек: id не пересозданы, не потеряны, не добавлены.
        $cardIdsAfter = SrsCard::query()->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        self::assertSame($cardIdsBefore, $cardIdsAfter, 'srs_cards.id must be unchanged by segmentation');

        // 2. Каждая строка srs_review_states пережила переезд (count + ссылки).
        self::assertSame($stateCountBefore, SrsReviewState::query()->count());
        self::assertSame(
            $statesBefore,
            SrsReviewState::query()->pluck('card_id')->sort()->values()->all(),
            'srs_review_states rows must be byte-for-byte the same card_id set',
        );

        // 3. Бандлы по частотному рангу.
        self::assertSame(['top500' => 3, 'top2000' => 3, 'rest' => 6], $moved);

        $deck->refresh();
        self::assertSame(0, $deck->cards()->count(), 'parent mega-deck is fully segmented');

        $children = $deck->children()->orderBy('slug')->get()->keyBy('slug');
        self::assertSame(
            ['sanskrit-core-500-2000', 'sanskrit-core-rest', 'sanskrit-core-top-500'],
            $children->keys()->sort()->values()->all(),
        );
        self::assertSame(3, $children['sanskrit-core-top-500']->cards()->count());
        self::assertSame(3, $children['sanskrit-core-500-2000']->cards()->count());
        self::assertSame(6, $children['sanskrit-core-rest']->cards()->count());

        // 4. Подколоды наследуют таксономию родителя и несут band-тег.
        foreach ($children as $child) {
            self::assertSame($deck->id, $child->parent_deck_id);
            self::assertSame('lexicon', $child->topic);
            self::assertSame('intermediate', $child->difficulty);
            self::assertNotEmpty(array_filter((array) $child->tags, fn ($t) => str_starts_with((string) $t, 'band:')));
            self::assertSame('small', $child->size_tier);
        }

        // 5. Идемпотентность: повторный запуск — no-op, инварианты держатся.
        self::assertSame([], $this->segmenter->segment($deck, ['aaa' => 10, 'bbb' => 700, 'ccc' => 3000]));
        self::assertSame($cardIdsBefore, SrsCard::query()->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all());
        self::assertSame($stateCountBefore, SrsReviewState::query()->count());
    }

    public function test_default_fixture_bands_are_rank_cutoffs(): void
    {
        // Дефолтная карта рангов читается из закоммиченных партиций kosha
        // (SLP1 → rank_all ≤ 2000): границы бандлов — 500 и 2000, хвост рангов
        // бандлам неотличим от «rest» и в фикстуру не входит.
        $ranks = $this->segmenter->ranks();

        self::assertNotEmpty($ranks);
        self::assertLessThanOrEqual(500, $ranks['ca'] ?? PHP_INT_MAX, 'rank 1 lemma must be in fixture');
        foreach ($ranks as $rank) {
            self::assertLessThanOrEqual(2000, $rank, 'fixture is cut at rank 2000 by design');
        }
    }

    public function test_public_hubs_hide_segmented_parent_deck(): void
    {
        ['deck' => $deck, 'cards' => $cardIds] = $this->makeMegaDeck();
        $this->segmenter->segment($deck, ['aaa' => 10, 'bbb' => 700, 'ccc' => 3000]);

        $this->get('/koloda')
            ->assertOk()
            ->assertSee('Санскрит — ядро словаря · топ-500')
            // Родитель скрыт из списка: якоря на его slug нет, у сегментов
            // slug продолжается на «-top-500», поэтому маска не задевает их.
            ->assertDontSee('/koloda/sanskrit-core"');
    }
}
