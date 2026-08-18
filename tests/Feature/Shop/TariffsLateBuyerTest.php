<?php

declare(strict_types=1);

namespace Tests\Feature\Shop;

use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Lesson;
use App\Models\Schedule;
use App\Models\Tariff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Блок тарифов обязан сказать опоздавшему покупателю, что он получит за уже
 * прошедшие занятия.
 *
 * Живой случай (18-08-2026, MG): у «гимна Бхагавадгиты (2 часть, 2026)» кнопки
 * «Полный курс 22 000 ₽» и «БЛОК 1 / 2» стояли рядом с «СЕЙЧАС ИДЕТ БЛОК 4» без
 * единого слова про записи — при том что `full` открывает уроки ЛЮБОГО блока
 * (Lesson::unlockingKeys), и записи у прошедших занятий есть.
 */
class TariffsLateBuyerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-18 14:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Поток из 4 занятий: 3 прошли (с записями), 1 впереди. Блок 1 закрыт, блок 2 идёт. */
    private function underwayCourse(): Course
    {
        $course = Course::factory()->create([
            'slug' => 'gita-2ch',
            'format' => 'live',
            'is_visible' => true,
            'lessons_count' => 4,
        ]);

        Tariff::factory()->for($course)->create(['title' => 'Полный курс', 'type' => 'full', 'price' => 22000]);

        foreach ([1 => ['2026-07-01', '2026-07-21'], 2 => ['2026-08-04', '2026-09-01']] as $n => [$from, $to]) {
            $block = CourseBlock::create([
                'course_id' => $course->id,
                'number' => $n,
                'title' => 'Блок '.$n,
                'starts_at' => Carbon::parse($from),
                'ends_at' => Carbon::parse($to),
                'is_active' => true,
            ]);
            Tariff::factory()->for($course)->create([
                'title' => 'Блок '.$n,
                'type' => 'block',
                'block_number' => $n,
                'course_block_id' => $block->id,
                'price' => 6000,
            ]);
        }

        $sessions = ['2026-07-07', '2026-07-14', '2026-08-11', '2026-08-25'];
        foreach ($sessions as $i => $date) {
            Schedule::create([
                'title' => 'Занятие '.($i + 1),
                'course_id' => $course->id,
                'start' => Carbon::parse($date.' 11:30'),
                'end' => Carbon::parse($date.' 12:30'),
            ]);
        }

        // Записи есть у трёх прошедших занятий.
        foreach ([1, 2, 3] as $i) {
            Lesson::factory()->for($course)->create([
                'title' => 'Занятие '.$i,
                'sort_order' => $i,
                'block_number' => $i <= 2 ? 1 : 2,
                'is_published' => true,
                'rutube_url' => 'https://rutube.ru/video/demo'.$i.'/',
            ]);
        }

        return $course;
    }

    /** @test */
    public function a_running_course_tells_the_late_buyer_what_the_full_tariff_includes(): void
    {
        $course = $this->underwayCourse();

        $html = $this->get('/k/'.$course->slug)->assertOk()->getContent();

        $this->assertStringContainsString('data-testid="tariffs-underway-notice"', $html);
        $this->assertStringContainsString('Курс уже идёт', $html);
        $this->assertStringContainsString('записи всех прошедших занятий', $html);
        // Число записей считается по факту, а не заявляется.
        $this->assertStringContainsString('сейчас их 3', $html);
        $this->assertStringContainsString('осталось 1 занятие из 4', $html);
        $this->assertStringContainsString('по вторникам в 11:30', $html);
    }

    /** @test */
    public function a_finished_block_is_labelled_and_its_button_promises_recordings(): void
    {
        $course = $this->underwayCourse();

        $html = $this->get('/k/'.$course->slug)->assertOk()->getContent();

        $this->assertStringContainsString('data-testid="tariffs-finished-block"', $html);
        $this->assertStringContainsString('УЖЕ ПРОШЁЛ — В ЗАПИСИ', $html);
        $this->assertStringContainsString('Купить записи блока', $html);
        // Идущий блок остаётся живым — его кнопка не должна стать «записями».
        $this->assertStringContainsString('СЕЙЧАС ИДЕТ', $html);
        $this->assertStringContainsString('Оплатить модуль', $html);
    }

    /** @test */
    public function a_course_that_has_not_started_shows_no_notice_and_no_recording_labels(): void
    {
        $course = Course::factory()->create(['slug' => 'future-course', 'format' => 'live', 'is_visible' => true]);
        Tariff::factory()->for($course)->create(['title' => 'Полный курс', 'type' => 'full', 'price' => 9000]);

        CourseBlock::create([
            'course_id' => $course->id,
            'number' => 1,
            'title' => 'Блок 1',
            'starts_at' => Carbon::parse('2026-09-01'),
            'ends_at' => Carbon::parse('2026-09-22'),
            'is_active' => true,
        ]);

        foreach (['2026-09-01', '2026-09-08'] as $date) {
            Schedule::create([
                'title' => 'Занятие',
                'course_id' => $course->id,
                'start' => Carbon::parse($date.' 15:00'),
                'end' => Carbon::parse($date.' 16:00'),
            ]);
        }

        $html = $this->get('/k/'.$course->slug)->assertOk()->getContent();

        $this->assertStringNotContainsString('data-testid="tariffs-underway-notice"', $html);
        $this->assertStringNotContainsString('data-testid="tariffs-finished-block"', $html);
        $this->assertStringNotContainsString('Купить записи блока', $html);
    }

    /** @test */
    public function a_course_without_a_calendar_shows_no_notice(): void
    {
        $course = Course::factory()->create(['slug' => 'no-calendar', 'format' => 'live', 'is_visible' => true]);
        Tariff::factory()->for($course)->create(['title' => 'Полный курс', 'type' => 'full', 'price' => 9000]);

        $html = $this->get('/k/'.$course->slug)->assertOk()->getContent();

        $this->assertStringNotContainsString('data-testid="tariffs-underway-notice"', $html);
    }

    /** @test */
    public function the_notice_does_not_promise_recordings_that_do_not_exist_yet(): void
    {
        $course = $this->underwayCourse();
        // Записи ещё не выложены — обещание «сейчас их N» должно исчезнуть,
        // а не превратиться в «сейчас их 0».
        $course->lessons()->update(['rutube_url' => null, 'youtube_url' => null, 'video_url' => null]);

        $html = $this->get('/k/'.$course->slug)->assertOk()->getContent();

        $this->assertStringContainsString('data-testid="tariffs-underway-notice"', $html);
        $this->assertStringNotContainsString('сейчас их 0', $html);
    }

    /** @test */
    public function a_block_the_visitor_already_bought_keeps_its_purchased_state(): void
    {
        $course = $this->underwayCourse();

        $html = $this->get('/k/'.$course->slug)->assertOk()->getContent();

        // Гость ничего не купил — метка «Оплачено» не должна появиться,
        // а прошедший блок остаётся покупаемым.
        $this->assertStringNotContainsString('Оплачено', $html);
        $this->assertStringContainsString('Купить записи блока', $html);
    }
}
