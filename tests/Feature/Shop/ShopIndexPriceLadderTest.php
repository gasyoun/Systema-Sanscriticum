<?php

namespace Tests\Feature\Shop;

use App\Models\Course;
use App\Models\Tariff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H1293 — лесенка цен с позиционированием на витрине /online: секция #ceny
 * с честными «от N ₽» из ProductLadderAnchors (включая новый якорь
 * «живой курс целиком») и JSON-LD OfferCatalog. Цены никогда не хардкодятся:
 * нет тарифов — нет чисел и нет schema-ноды, секция при этом остаётся.
 */
class ShopIndexPriceLadderTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function index_renders_price_ladder_narrative_section(): void
    {
        $this->get('/online')
            ->assertOk()
            ->assertSee('data-analytics="price-ladder-narrative"', false)
            ->assertSee('id="ceny"', false)
            ->assertSee('Сколько стоит обучение')
            ->assertSee('Как устроены цены');
    }

    /** @test */
    public function ladder_shows_honest_min_prices_from_active_tariffs(): void
    {
        $live = Course::factory()->live()->create();
        Tariff::factory()->for($live)->create(['type' => 'block', 'price' => 4500, 'is_active' => true]);
        Tariff::factory()->for($live)->create(['type' => 'full', 'price' => 21000, 'is_active' => true]);

        $recorded = Course::factory()->create(['format' => 'recorded']);
        Tariff::factory()->for($recorded)->create(['type' => 'full', 'price' => 1900, 'is_active' => true]);

        $this->get('/online')
            ->assertOk()
            ->assertSee('от 1 900 ₽ за курс')
            ->assertSee('от 4 500 ₽ за блок')
            ->assertSee('от 21 000 ₽')
            ->assertSee('OfferCatalog');
    }

    /** @test */
    public function without_tariffs_section_degrades_without_invented_numbers(): void
    {
        $this->get('/online')
            ->assertOk()
            ->assertSee('data-analytics="price-ladder-narrative"', false)
            ->assertDontSee('OfferCatalog')
            ->assertDontSee('за блок');
    }
}
