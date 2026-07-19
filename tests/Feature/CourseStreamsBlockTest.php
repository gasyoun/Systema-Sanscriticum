<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\LandingPage;
use App\Models\Tariff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourseStreamsBlockTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function block_renders_streams_with_checkout_links_carrying_promo(): void
    {
        $course = Course::factory()->create();
        $tariff = Tariff::factory()->create(['course_id' => $course->id, 'price' => 12000]);

        $landing = LandingPage::create([
            'title' => 'Курс с потоками',
            'slug' => 'course-with-streams',
            'is_active' => true,
            'content' => [[
                'type' => 'course_streams_block',
                'data' => [
                    'title' => 'Выберите поток',
                    'streams' => [
                        [
                            'name' => 'Поток 1',
                            'schedule' => 'Пн/Ср 19:00',
                            'tariff_id' => $tariff->id,
                            'promo_code' => 'AUTUMN10',
                        ],
                    ],
                ],
            ]],
        ]);

        $response = $this->get('/'.$landing->slug);

        $response->assertOk();
        $response->assertSee('Пн/Ср 19:00');
        $response->assertSee('/checkout/'.$tariff->id.'?promo=AUTUMN10', false);
    }

    /** @test */
    public function block_with_real_deadline_and_seats_renders_honest_scarcity(): void
    {
        $course = Course::factory()->create();
        $tariff = Tariff::factory()->create(['course_id' => $course->id]);

        $landing = LandingPage::create([
            'title' => 'Курс с дедлайном',
            'slug' => 'course-with-timer',
            'is_active' => true,
            'content' => [[
                'type' => 'course_streams_block',
                'data' => [
                    'timer_end' => '2099-01-01 12:00:00',
                    'seats_taken' => 7,
                    'seats_total' => 10,
                    'streams' => [
                        ['name' => 'Поток 1', 'schedule' => 'Пн/Ср 19:00', 'tariff_id' => $tariff->id],
                    ],
                ],
            ]],
        ]);

        $response = $this->get('/'.$landing->slug);

        $response->assertOk();
        $response->assertSee('Текущая цена действует до');
        $response->assertSee('1 января, 12:00');       // дата словами вместо тикающего отсчета
        $response->assertSee('Свободных мест:');
        $response->assertSee('из 10');                 // реальные числа из конфига
        $response->assertDontSee('Повышение цены через:');
        $response->assertDontSee('Осталось мало!');
        $response->assertDontSee('Акция');
    }

    /** @test */
    public function block_without_scarcity_config_renders_neither_deadline_nor_seats(): void
    {
        $course = Course::factory()->create();
        $tariff = Tariff::factory()->create(['course_id' => $course->id]);

        $landing = LandingPage::create([
            'title' => 'Курс без таймера',
            'slug' => 'course-no-timer',
            'is_active' => true,
            'content' => [[
                'type' => 'course_streams_block',
                'data' => [
                    'streams' => [
                        ['name' => 'Поток 1', 'schedule' => 'Пн/Ср 19:00', 'tariff_id' => $tariff->id],
                    ],
                ],
            ]],
        ]);

        $response = $this->get('/'.$landing->slug);

        $response->assertOk();
        $response->assertSee('Пн/Ср 19:00');
        $response->assertDontSee('Повышение цены через:');
        $response->assertDontSee('Текущая цена действует до');
        $response->assertDontSee('Доступно мест:');
        $response->assertDontSee('Свободных мест:');
    }

    /** @test */
    public function expired_deadline_is_not_rendered(): void
    {
        $course = Course::factory()->create();
        $tariff = Tariff::factory()->create(['course_id' => $course->id]);

        $landing = LandingPage::create([
            'title' => 'Курс с истекшим дедлайном',
            'slug' => 'course-expired-timer',
            'is_active' => true,
            'content' => [[
                'type' => 'course_streams_block',
                'data' => [
                    'timer_end' => '2020-01-01 12:00:00',
                    'streams' => [
                        ['name' => 'Поток 1', 'schedule' => 'Пн/Ср 19:00', 'tariff_id' => $tariff->id],
                    ],
                ],
            ]],
        ]);

        $response = $this->get('/'.$landing->slug);

        $response->assertOk();
        $response->assertDontSee('Текущая цена действует до');
    }

    /** @test */
    public function stream_without_tariff_is_skipped_and_does_not_error(): void
    {
        $landing = LandingPage::create([
            'title' => 'Битый блок',
            'slug' => 'broken-streams',
            'is_active' => true,
            'content' => [[
                'type' => 'course_streams_block',
                'data' => [
                    'streams' => [
                        ['name' => 'Без тарифа', 'schedule' => 'Сб 10:00'],
                    ],
                ],
            ]],
        ]);

        $response = $this->get('/'.$landing->slug);

        $response->assertOk();
        $response->assertDontSee('Сб 10:00');
    }
}
