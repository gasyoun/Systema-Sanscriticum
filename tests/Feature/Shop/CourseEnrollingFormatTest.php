<?php

declare(strict_types=1);

namespace Tests\Feature\Shop;

use App\Livewire\Shop\CourseCatalog;
use App\Models\Course;
use App\Models\Schedule;
use App\Models\Tariff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Третий формат курса 'enrolling' («Идет набор») — живой курс, группа ещё
 * не стартовала. Покрывает: бейдж на карточке, секцию и чип-фильтр каталога,
 * фасет-URL /online/format/enrolling, лендинг курса. Словарь витрины —
 * без «ё», как у live/recorded (H2379).
 */
class CourseEnrollingFormatTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function enrolling_course_renders_badge_and_own_catalog_section(): void
    {
        Course::factory()->create(['title' => 'Enrolling Course Qqq', 'format' => 'enrolling']);

        Livewire::test(CourseCatalog::class)
            ->assertSee('Идет набор')
            ->assertSee('Enrolling Course Qqq');
    }

    /** @test */
    public function enrolling_filter_leaves_only_enrolling_courses(): void
    {
        Course::factory()->create(['title' => 'Enrolling Course Qqq', 'format' => 'enrolling']);
        Course::factory()->create(['title' => 'Live Course Rrr', 'format' => 'live']);
        Course::factory()->create(['title' => 'Recorded Course Sss', 'format' => 'recorded']);

        Livewire::test(CourseCatalog::class, ['initialFormat' => 'enrolling'])
            ->assertSee('Enrolling Course Qqq')
            ->assertDontSee('Live Course Rrr')
            ->assertDontSee('Recorded Course Sss');
    }

    /** @test */
    public function enrolling_facet_url_is_accepted_and_prefiltered(): void
    {
        Course::factory()->create(['title' => 'Enrolling Course Qqq', 'format' => 'enrolling']);
        Course::factory()->create(['title' => 'Recorded Course Sss', 'format' => 'recorded']);

        $this->get('/online/format/enrolling')
            ->assertOk()
            ->assertSee('Enrolling Course Qqq')
            ->assertDontSee('Recorded Course Sss');
    }

    /** @test */
    public function unknown_format_value_is_a_real_404(): void
    {
        $this->get('/online/format/nope')->assertNotFound();
    }

    /** @test */
    public function enrolling_course_landing_shows_the_badge(): void
    {
        $course = Course::factory()->create(['title' => 'Enrolling Course Qqq', 'format' => 'enrolling']);
        Tariff::factory()->for($course)->create(['title' => 'Весь курс целиком']);

        $this->get('/k/'.$course->slug)
            ->assertOk()
            ->assertSee('Идет набор');
    }

    /** @test */
    public function format_helpers_recognize_enrolling(): void
    {
        $course = Course::factory()->make(['format' => 'enrolling']);

        $this->assertTrue($course->isEnrolling());
        $this->assertFalse($course->isLive());
        $this->assertSame('Идет набор', $course->formatLabel());
    }

    /** @test */
    public function recruitment_label_switches_to_late_enrollment_after_first_lesson(): void
    {
        $course = Course::factory()->create(['title' => 'Enrolling Course Qqq', 'format' => 'enrolling']);

        $this->assertSame('Идет набор', $course->recruitmentLabel());
        $this->assertFalse($course->isLateEnrollment());

        // Поток начался (занятие прошло) и ещё не кончился — режим донабора.
        Schedule::create(['title' => 'Зан. 1', 'start' => now()->subWeek(), 'end' => now()->subWeek()->addHours(2), 'course_id' => $course->id]);
        Schedule::create(['title' => 'Зан. 2', 'start' => now()->addWeek(), 'end' => now()->addWeek()->addHours(2), 'course_id' => $course->id]);

        $this->assertSame('Продолжается донабор', $course->recruitmentLabel());
        $this->assertTrue($course->isLateEnrollment());
    }

    /** @test */
    public function late_enrollment_starts_with_the_first_lesson_not_after_its_end(): void
    {
        $course = Course::factory()->create(['title' => 'Enrolling Course Qqq', 'format' => 'enrolling']);

        // Первое занятие идёт прямо сейчас (началось 10 минут назад).
        Schedule::create(['title' => 'Зан. 1', 'start' => now()->subMinutes(10), 'end' => now()->addHours(2), 'course_id' => $course->id]);
        Schedule::create(['title' => 'Зан. 2', 'start' => now()->addWeek(), 'end' => now()->addWeek()->addHours(2), 'course_id' => $course->id]);

        $this->assertTrue($course->isLateEnrollment());
        $this->assertSame('Продолжается донабор', $course->recruitmentLabel());
    }

    /** @test */
    public function enrolling_course_before_first_lesson_stays_a_plain_recruitment(): void
    {
        $course = Course::factory()->create(['title' => 'Enrolling Course Qqq', 'format' => 'enrolling']);

        Schedule::create(['title' => 'Зан. 1', 'start' => now()->addDay(), 'end' => now()->addDay()->addHours(2), 'course_id' => $course->id]);

        $this->assertFalse($course->isLateEnrollment());
        $this->assertSame('Идет набор', $course->recruitmentLabel());
    }

    /** @test */
    public function finished_enrolling_course_is_no_longer_late_enrollment(): void
    {
        $course = Course::factory()->create(['title' => 'Enrolling Course Qqq', 'format' => 'enrolling']);
        Schedule::create(['title' => 'Зан. 1', 'start' => now()->subWeek(), 'end' => now()->subWeek()->addHours(2), 'course_id' => $course->id]);

        $this->assertFalse($course->isLateEnrollment());
        $this->assertSame('Идет набор', $course->recruitmentLabel());
    }

    /** @test */
    public function late_enrollment_landing_shows_late_donabor_badge(): void
    {
        $course = Course::factory()->create(['title' => 'Enrolling Course Qqq', 'format' => 'enrolling']);
        Tariff::factory()->for($course)->create(['title' => 'Блок 1']);
        Schedule::create(['title' => 'Зан. 1', 'start' => now()->subWeek(), 'end' => now()->subWeek()->addHours(2), 'course_id' => $course->id]);
        Schedule::create(['title' => 'Зан. 2', 'start' => now()->addWeek(), 'end' => now()->addWeek()->addHours(2), 'course_id' => $course->id]);

        $this->get('/k/'.$course->slug)
            ->assertOk()
            ->assertSee('Продолжается донабор')
            ->assertDontSee('Идет набор');
    }

    /** @test */
    public function catalog_card_shows_late_donabor_for_started_group(): void
    {
        $course = Course::factory()->create(['title' => 'Enrolling Course Qqq', 'format' => 'enrolling']);
        Schedule::create(['title' => 'Зан. 1', 'start' => now()->subWeek(), 'end' => now()->subWeek()->addHours(2), 'course_id' => $course->id]);
        Schedule::create(['title' => 'Зан. 2', 'start' => now()->addWeek(), 'end' => now()->addWeek()->addHours(2), 'course_id' => $course->id]);

        Livewire::test(CourseCatalog::class)->assertSee('Продолжается донабор');
    }
}
