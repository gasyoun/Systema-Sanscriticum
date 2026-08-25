<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Tariff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Лендинг «Основы цифровой грамотности» (лестница MG 24-08-2026): страница
 * живёт только при features.diglit_landing, цены приходят из тарифов курса
 * diglit-2026 (Filament), записи-даунселл не продаётся, пока неактивна,
 * и на странице нет обещаний дохода и удостоверения ПК.
 */
class DiglitLandingPageTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();

        $this->course = Course::create([
            'title' => 'Основы цифровой грамотности',
            'slug' => 'diglit-2026',
            'is_visible' => false,
            'lessons_count' => 16,
            'hours_count' => 24,
        ]);

        $this->tariff('Ранние птицы', 14900, 'Полный формат потока. Первым десяти записавшимся или до 15 сентября.');
        $this->tariff('Основной', 19900, 'Живые занятия + проверка домашних заданий + чат потока + записи навсегда.');
        $this->tariff('VIP-группа', 34900, 'Группа до пяти человек и личные разборы ваших работ.');
        $this->recording('Записи + чат', 8900);
    }

    private function tariff(string $title, int $price, string $description): Tariff
    {
        return Tariff::create([
            'course_id' => $this->course->id,
            'title' => $title,
            'type' => 'full',
            'price' => $price,
            'description' => $description,
            'is_active' => true,
        ]);
    }

    private function recording(string $title, int $price): Tariff
    {
        return Tariff::create([
            'course_id' => $this->course->id,
            'title' => $title,
            'type' => 'full',
            'price' => $price,
            'is_recording' => true,
            'is_active' => false,
            'description' => 'Все записи и общий чат, без проверки домашних заданий.',
        ]);
    }

    public function test_404_while_diglit_landing_flag_is_off(): void
    {
        config()->set('features.diglit_landing', false);

        $this->get('/online/cifrovaya-gramotnost')->assertNotFound();
    }

    public function test_404_without_course_or_without_active_ladder(): void
    {
        config()->set('features.diglit_landing', true);

        $this->course->delete();
        $this->get('/online/cifrovaya-gramotnost')->assertNotFound();
    }

    public function test_prices_come_from_db_and_ctas_lead_to_checkout(): void
    {
        config()->set('features.diglit_landing', true);

        $response = $this->get('/online/cifrovaya-gramotnost')->assertOk();

        // Цены — из строк БД, в формате магазина.
        $response->assertSee('14 900');
        $response->assertSee('19 900');
        $response->assertSee('34 900');

        // CTA каждого тарифа лестницы ведёт в его реальный чекаут.
        foreach ($this->course->tariffs()->where('is_active', true)->get() as $tariff) {
            $response->assertSee(route('checkout.show', $tariff), false);
        }

        // Неактивный даунселл на странице не продаётся.
        $recordingsTariff = $this->course->tariffs()->where('is_recording', true)->firstOrFail();
        $response->assertDontSee(route('checkout.show', $recordingsTariff), false);
    }

    public function test_verified_stats_and_honesty_constraints_are_visible(): void
    {
        config()->set('features.diglit_landing', true);

        $response = $this->get('/online/cifrovaya-gramotnost')->assertOk();

        // Сверенные цифры (PwC; Google/Ipsos) — с оговоркой базы сравнения.
        $response->assertSee('+56 %');
        $response->assertSee('5 % работников');
        $response->assertSee('в 4,5 раза чаще');

        // Честностные ограничения: корочка ДПО названа в FAQ только для
        // прямого отказа, обещаний заработка на странице нет.
        $response->assertSee('Нет. Выдаём собственный сертификат школы.');
        $response->assertDontSee('выдаём удостоверение о повышении квалификации');
        $response->assertDontSee(mb_strtolower('гарантия дохода'));
        $response->assertDontSee('гарантируем заработок');
    }

    public function test_recording_downsell_appears_once_activated(): void
    {
        config()->set('features.diglit_landing', true);

        $this->course->tariffs()->where('is_recording', true)->update(['is_active' => true]);

        $recordingsTariff = $this->course->tariffs()->where('is_recording', true)->firstOrFail();

        $this->get('/online/cifrovaya-gramotnost')
            ->assertOk()
            ->assertSee(route('checkout.show', $recordingsTariff), false);
    }
}
