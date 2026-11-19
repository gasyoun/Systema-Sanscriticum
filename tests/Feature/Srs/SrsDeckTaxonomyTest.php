<?php

declare(strict_types=1);

namespace Tests\Feature\Srs;

use App\Models\SrsDeck;
use App\Models\SrsNoteType;
use App\Models\User;
use Database\Seeders\SrsTaxonomySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H7966 (трек А) — таксономия колод + discovery-фильтры на обеих
 * поверхностях (/koloda и /dvaram/koloda), обе за существующим SRS_ENABLED.
 *
 * Покрытие acceptance:
 *  - офлайн-проход SrsTaxonomySeeder проставляет topic/difficulty/size_tier;
 *  - фильтр ?topic= реально режет выдачу публичного и кабинетного хаба;
 *  - фильтры рендерятся (blade) на обеих поверхностях;
 *  - при srs.enabled=false обе поверхности отдают 404 (флаг не обойдён).
 */
class SrsDeckTaxonomyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['srs.enabled' => true]);
    }

    private function makeDeck(string $slug, string $name, ?string $topic = null, ?string $difficulty = null): SrsDeck
    {
        $noteType = SrsNoteType::firstOrCreate(
            ['key' => 'sanskrit_basic'],
            [
                'name' => 'Санскрит — базовая',
                'language' => 'sa',
                'fields' => ['devanagari', 'iast', 'translation'],
            ],
        );

        return SrsDeck::create([
            'note_type_id' => $noteType->id,
            'name' => $name,
            'slug' => $slug,
            'language' => 'sa',
            'visibility' => 'system',
            'topic' => $topic,
            'difficulty' => $difficulty,
        ]);
    }

    public function test_taxonomy_seeder_sets_curated_topics_and_size_tiers(): void
    {
        $this->makeDeck('sanskrit-roots-frequency', 'Корни по частоте');
        $this->makeDeck('unknown-deck', 'Неизвестная колода');

        $this->seed(SrsTaxonomySeeder::class);

        $roots = SrsDeck::query()->where('slug', 'sanskrit-roots-frequency')->first();
        self::assertSame('roots', $roots->topic);
        self::assertSame('intermediate', $roots->difficulty);
        self::assertSame('small', $roots->size_tier, 'size_tier computed for every deck, not only mega');

        $unknown = SrsDeck::query()->where('slug', 'unknown-deck')->first();
        self::assertNull($unknown->topic, 'non-curated decks stay untagged until the teacher approves in Filament');
        self::assertSame('small', $unknown->size_tier);
    }

    public function test_size_tier_thresholds(): void
    {
        self::assertSame('small', SrsDeck::sizeTierFor(0));
        self::assertSame('small', SrsDeck::sizeTierFor(99));
        self::assertSame('medium', SrsDeck::sizeTierFor(100));
        self::assertSame('medium', SrsDeck::sizeTierFor(999));
        self::assertSame('large', SrsDeck::sizeTierFor(1000));
        self::assertSame('large', SrsDeck::sizeTierFor(4999));
        self::assertSame('mega', SrsDeck::sizeTierFor(5000));
        self::assertSame('mega', SrsDeck::sizeTierFor(11685));
    }

    public function test_public_koloda_renders_filters_and_filters_by_topic(): void
    {
        $this->makeDeck('xtest-roots', 'XTESTA Корневая колода', 'roots', 'intermediate');
        $this->makeDeck('xtest-lex', 'XTESTA Лексическая колода', 'lexicon', 'beginner');

        $this->get('/koloda')
            ->assertOk()
            ->assertSee('data-testid="srs-taxonomy-filters"', false)
            ->assertSee('XTESTA Корневая колода')
            ->assertSee('XTESTA Лексическая колода')
            ->assertSee('Корни', false); // бейдж темы рендерится

        $this->get('/koloda?topic=roots')
            ->assertOk()
            ->assertSee('XTESTA Корневая колода')
            ->assertDontSee('XTESTA Лексическая колода');

        $this->get('/koloda?difficulty=beginner')
            ->assertOk()
            ->assertSee('XTESTA Лексическая колода')
            ->assertDontSee('XTESTA Корневая колода');
    }

    public function test_cabinet_hub_renders_filters_and_filters_by_topic_and_size(): void
    {
        $user = User::factory()->create();
        $this->makeDeck('xtest-roots', 'XTESTA Корневая колода', 'roots', 'intermediate');
        $lex = $this->makeDeck('xtest-lex', 'XTESTA Лексическая колода', 'lexicon', 'beginner');
        $lex->forceFill(['size_tier' => 'medium'])->save();

        $this->actingAs($user)
            ->get('/dvaram/koloda')
            ->assertOk()
            ->assertSee('data-testid="srs-taxonomy-filters"', false)
            ->assertSee('XTESTA Корневая колода')
            ->assertSee('XTESTA Лексическая колода');

        $this->actingAs($user)
            ->get('/dvaram/koloda?topic=lexicon')
            ->assertOk()
            ->assertSee('XTESTA Лексическая колода')
            ->assertDontSee('XTESTA Корневая колода');

        $this->actingAs($user)
            ->get('/dvaram/koloda?size=medium')
            ->assertOk()
            ->assertSee('XTESTA Лексическая колода')
            ->assertDontSee('XTESTA Корневая колода');
    }

    public function test_taxonomy_filter_values_outside_vocabularies_do_not_filter(): void
    {
        $this->makeDeck('xtest-roots', 'XTESTA Корневая колода', 'roots');

        // Мимо словаря SrsDeck::TOPICS фильтр не применяется (инъекция value→колонка невозможна).
        $this->get('/koloda?topic=nonexistent')
            ->assertOk()
            ->assertSee('XTESTA Корневая колода');
    }

    public function test_both_surfaces_stay_behind_srs_enabled_flag(): void
    {
        $this->makeDeck('xtest-roots', 'XTESTA Корневая колода', 'roots');
        config(['srs.enabled' => false]);

        $this->get('/koloda?topic=roots')->assertNotFound();
        $this->actingAs(User::factory()->create())
            ->get('/dvaram/koloda?topic=roots')
            ->assertNotFound();
    }
}
