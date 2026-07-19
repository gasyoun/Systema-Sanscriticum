<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LandingPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Честный дефицит в price_block (H1287, D4): без реальных настроенных данных
 * ни отсчет, ни счетчик мест не рендерятся — блок деградирует до честного
 * прайса, а не до фальшивого «16/20 мест» с вечным таймером «+24 часа».
 */
class PriceBlockTest extends TestCase
{
    use RefreshDatabase;

    private function makeLanding(array $blockData, string $slug = 'price-landing'): LandingPage
    {
        return LandingPage::create([
            'title' => 'Лендинг с тарифами',
            'slug' => $slug,
            'is_active' => true,
            'content' => [[
                'type' => 'price_block',
                'data' => $blockData + [
                    'title' => 'Стоимость участия',
                    'tariffs' => [
                        ['name' => 'Базовый', 'price' => '12 000 ₽', 'features' => '<ul><li>Все занятия</li></ul>'],
                    ],
                ],
            ]],
        ]);
    }

    /** @test */
    public function empty_scarcity_config_renders_neither_countdown_nor_seat_bar(): void
    {
        $landing = $this->makeLanding([]);

        $response = $this->get('/'.$landing->slug);

        $response->assertOk();
        $response->assertSee('Базовый');
        $response->assertDontSee('Повышение цены через:');
        $response->assertDontSee('Текущая цена действует до');
        $response->assertDontSee('Доступно мест:');
        $response->assertDontSee('Свободных мест:');
        $response->assertDontSee('Осталось мало!');
        $response->assertDontSee('Акция');
    }

    /** @test */
    public function webinar_date_no_longer_fabricates_a_price_deadline(): void
    {
        $landing = $this->makeLanding([], 'price-landing-webinar');
        $landing->update(['webinar_date' => now()->addDays(3)]);

        $response = $this->get('/'.$landing->slug);

        $response->assertOk();
        $response->assertDontSee('Повышение цены через:');
        $response->assertDontSee('Текущая цена действует до');
    }

    /** @test */
    public function real_deadline_renders_dated_statement_not_countdown(): void
    {
        $landing = $this->makeLanding(['timer_end' => '2099-01-01 12:00:00'], 'price-landing-deadline');

        $response = $this->get('/'.$landing->slug);

        $response->assertOk();
        $response->assertSee('Текущая цена действует до');
        $response->assertSee('1 января, 12:00');
        $response->assertSee('После этой даты стоимость будет выше.');
        $response->assertDontSee('Повышение цены через:');
        // Мест не настроено — счетчик не показывается даже при настроенном дедлайне.
        $response->assertDontSee('Свободных мест:');
    }

    /** @test */
    public function expired_deadline_is_not_rendered(): void
    {
        $landing = $this->makeLanding(['timer_end' => '2020-01-01 12:00:00'], 'price-landing-expired');

        $response = $this->get('/'.$landing->slug);

        $response->assertOk();
        $response->assertDontSee('Текущая цена действует до');
    }

    /** @test */
    public function real_seats_render_real_numbers_without_pressure_badge(): void
    {
        $landing = $this->makeLanding(['seats_taken' => 12, 'seats_total' => 30], 'price-landing-seats');

        $response = $this->get('/'.$landing->slug);

        $response->assertOk();
        $response->assertSee('Свободных мест:');
        $response->assertSee('из 30');
        $response->assertDontSee('Осталось мало!');
        $response->assertDontSee('Забронировано');
    }

    /** @test */
    public function partial_or_invalid_seats_config_renders_no_seat_bar(): void
    {
        // Только одно из двух чисел — счетчик не рендерится: половина правды не рисуется.
        $landing = $this->makeLanding(['seats_total' => 20], 'price-landing-partial-seats');

        $response = $this->get('/'.$landing->slug);

        $response->assertOk();
        $response->assertDontSee('Свободных мест:');
    }
}
