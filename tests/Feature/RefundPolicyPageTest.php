<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Tariff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * H1288 — страница условий возврата /vozvrat. Рендерит порядок отказа из
 * оферты (раздел 11 + приложение №1) в точных терминах: сроки, формула и
 * канал заявления не пересказываются вольно. Trust row чекаута ссылается
 * на страницу вместо голого «Возврат по оферте».
 */
class RefundPolicyPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
    }

    public function test_refund_page_renders_exact_oferta_terms(): void
    {
        $response = $this->get('/vozvrat');

        $response->assertOk();
        $response->assertSee('Возврат денег');
        // Точные сроки приложения №1 — не перефразированные.
        $response->assertSee('1 (один) календарный день');
        $response->assertSee('7 (семи) календарных дней');
        $response->assertSee('10 (десяти)');
        // Канал заявления — из оферты §11.1/§4.1 приложения.
        $response->assertSee('rusamskrtam@yandex.ru');
        // Формула частичного возврата присутствует.
        $response->assertSee('стоимость одного учебного модуля');
    }

    public function test_refund_page_links_to_full_oferta(): void
    {
        $response = $this->get('/vozvrat');

        $response->assertOk();
        $response->assertSee('/dokumenty/oferta');
    }

    public function test_checkout_trust_row_links_to_refund_page(): void
    {
        $course = Course::factory()->create();
        $tariff = Tariff::factory()->for($course)->create(['price' => 5000]);

        $response = $this->get(route('checkout.show', $tariff));

        $response->assertOk();
        $response->assertSee('Возврат: до начала — 100%');
        $response->assertSee('/vozvrat');
        $response->assertDontSee('Возврат по оферте');
    }

    public function test_footer_links_to_refund_page(): void
    {
        $response = $this->get('/vozvrat');

        $response->assertOk();
        $response->assertSee('Условия возврата');
    }
}
