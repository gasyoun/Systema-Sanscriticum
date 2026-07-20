<?php

namespace Tests\Feature\Shop;

use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Payment;
use App\Models\Schedule;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * H1291 — микрокопия возражений в точке продажи. ВРЕМЯ и ЦЕНА (537 из 679
 * зафиксированных возражений корпуса H474/H482) отвечены там, где возникают:
 * у расписания, у тарифной сетки, у цены «/ блок» на карточке каталога.
 * Правило честности: ни одной строки без основания в продукте — блочная
 * строка только при блочных тарифах, запрос «по частям» только при
 * настроенном кураторском чате (та же калитка, что у H1290 на чекауте),
 * «Возврат: до начала — 100%» всегда ссылкой на /vozvrat (shared string 4).
 * Формулировки и решения: docs/copy/money-objection-corpus-pos-microcopy.md.
 */
class ObjectionPosMicrocopyTest extends TestCase
{
    use RefreshDatabase;

    /** Курс с полным и блочным тарифом — базовое состояние точки продажи. */
    private function makeCourseWithBlockTariff(string $slug): Course
    {
        $course = Course::factory()->create(['slug' => $slug]);
        Tariff::factory()->for($course)->create();

        $block = CourseBlock::factory()->for($course)->create(['number' => 1]);
        Tariff::factory()->for($course)->block(1)->create(['course_block_id' => $block->id]);

        return $course->fresh();
    }

    /** @test */
    public function price_microcopy_explains_block_payment_when_block_tariffs_exist(): void
    {
        $course = $this->makeCourseWithBlockTariff('blocks-course');

        $this->get('/online/kursy/'.$course->slug)
            ->assertOk()
            ->assertSee('data-analytics="objection-price-microcopy"', false)
            ->assertSee('Платить за весь курс сразу не нужно')
            ->assertSee('обычно это 4 занятия');
    }

    /** @test */
    public function block_principle_line_is_hidden_for_full_only_course(): void
    {
        $course = Course::factory()->create(['slug' => 'full-only-course']);
        Tariff::factory()->for($course)->create();

        $this->get('/online/kursy/'.$course->slug)
            ->assertOk()
            ->assertSee('data-analytics="objection-price-microcopy"', false)
            ->assertDontSee('Платить за весь курс сразу не нужно');
    }

    /** @test */
    public function installments_pointer_renders_only_with_configured_curators_chat(): void
    {
        config(['services.telegram.curators_chat_id' => '-100123']);
        $course = $this->makeCourseWithBlockTariff('with-curators');

        $this->get('/online/kursy/'.$course->slug)
            ->assertOk()
            ->assertSee('Нужно разбить оплату на части?')
            ->assertSee('запрос куратору ни к чему не обязывает');

        config(['services.telegram.curators_chat_id' => null]);
        $course2 = $this->makeCourseWithBlockTariff('without-curators');

        $this->get('/online/kursy/'.$course2->slug)
            ->assertOk()
            ->assertDontSee('Нужно разбить оплату на части?');
    }

    /** @test */
    public function benefit_price_line_and_refund_link_render_near_tariffs(): void
    {
        $course = $this->makeCourseWithBlockTariff('benefit-course');

        $this->get('/online/kursy/'.$course->slug)
            ->assertOk()
            ->assertSee('Пенсионерам, студентам и многодетным')
            ->assertSee('на многих курсах действует льготная цена')
            ->assertSee('https://t.me/rusamskrtam', false)
            ->assertSee('Возврат: до начала — 100%')
            ->assertSee(route('refund.show'), false);
    }

    /** @test */
    public function block_principle_line_is_hidden_for_halves_only_course(): void
    {
        $course = Course::factory()->create(['slug' => 'halves-only-course']);
        $block = CourseBlock::factory()->for($course)->create(['number' => 1]);
        foreach ([1, 2] as $half) {
            Tariff::factory()->for($course)->block(1)->create([
                'course_block_id' => $block->id,
                'block_half' => $half,
                'price' => 2400,
            ]);
        }

        // Целого блока к покупке нет — обещание «оплачиваете ближайший блок»
        // было бы ложным; остальная микрокопия остается.
        $this->get('/online/kursy/'.$course->slug)
            ->assertOk()
            ->assertSee('data-analytics="objection-price-microcopy"', false)
            ->assertDontSee('Платить за весь курс сразу не нужно');
    }

    /** @test */
    public function price_microcopy_hidden_for_full_course_purchaser(): void
    {
        $user = User::factory()->create();
        $course = $this->makeCourseWithBlockTariff('purchased-course');

        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 12000,
            'tariff' => 'full',
            'status' => 'paid',
        ]);

        $this->actingAs($user)
            ->get('/online/kursy/'.$course->slug)
            ->assertOk()
            ->assertDontSee('objection-price-microcopy')
            ->assertDontSee('льготная цена');
    }

    /** @test */
    public function price_microcopy_absent_when_course_has_no_tariffs(): void
    {
        Course::factory()->create(['slug' => 'no-tariffs-course']);

        $this->get('/online/kursy/no-tariffs-course')
            ->assertOk()
            ->assertDontSee('objection-price-microcopy')
            ->assertDontSee('льготная цена');
    }

    /** @test */
    public function schedule_reassurance_renders_with_upcoming_sessions(): void
    {
        Carbon::setTestNow('2026-06-25 12:00:00');
        $course = Course::factory()->create(['slug' => 'sched-micro-course']);
        Tariff::factory()->for($course)->create();

        Schedule::create([
            'title' => 'Занятие 1',
            'start' => Carbon::parse('2026-06-28 20:00:00'),
            'end' => Carbon::parse('2026-06-28 22:00:00'),
            'course_id' => $course->id,
        ]);

        $this->get('/online/kursy/'.$course->slug)
            ->assertOk()
            ->assertSee('data-analytics="objection-time-microcopy"', false)
            ->assertSee('Не попадаете по времени?')
            ->assertSee('Пропуск не выбивает из курса');
    }

    /** @test */
    public function schedule_reassurance_absent_without_sessions(): void
    {
        $course = Course::factory()->create(['slug' => 'no-sched-micro']);
        Tariff::factory()->for($course)->create();

        $this->get('/online/kursy/'.$course->slug)
            ->assertOk()
            ->assertDontSee('objection-time-microcopy')
            ->assertDontSee('Не попадаете по времени?');
    }

    /** @test */
    public function catalog_card_glosses_the_block_unit(): void
    {
        $course = Course::factory()->create();
        Tariff::factory()->for($course)->create();
        $block = CourseBlock::factory()->for($course)->create(['number' => 1]);
        Tariff::factory()->for($course)->block(1)->create(['course_block_id' => $block->id]);

        $this->get('/online')
            ->assertOk()
            ->assertSee('Блок — обычно 4 занятия.');
    }

    /** @test */
    public function catalog_card_gloss_absent_without_block_tariffs(): void
    {
        $course = Course::factory()->create();
        Tariff::factory()->for($course)->create();

        $this->get('/online')
            ->assertOk()
            ->assertSee($course->title)
            ->assertDontSee('Блок — обычно 4 занятия.');
    }

    /** @test */
    public function catalog_card_gloss_absent_when_cheapest_block_tariff_is_a_half(): void
    {
        $course = Course::factory()->create();
        Tariff::factory()->for($course)->create();
        $block = CourseBlock::factory()->for($course)->create(['number' => 1]);
        Tariff::factory()->for($course)->block(1)->create([
            'course_block_id' => $block->id,
            'block_half' => 1,
            'price' => 2400,
        ]);

        // Цена «/ блок» на карточке — половинная: глосса «4 занятия»
        // удешевляла бы блок вдвое, поэтому не показывается.
        $this->get('/online')
            ->assertOk()
            ->assertSee($course->title)
            ->assertDontSee('Блок — обычно 4 занятия.');
    }

    /** @test */
    public function course_page_never_says_rassrochka(): void
    {
        config(['services.telegram.curators_chat_id' => '-100123']);
        $course = $this->makeCourseWithBlockTariff('register-course');

        // Регистр волны: на студенческих поверхностях — «оплата по частям»,
        // никогда «рассрочка» (кредитная коннотация; правило H1290).
        // Стебель без первой буквы ловит и «Рассрочка», и «рассрочка».
        $this->get('/online/kursy/'.$course->slug)
            ->assertOk()
            ->assertDontSee('ассрочк');
    }
}
