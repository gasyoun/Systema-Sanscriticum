<?php

declare(strict_types=1);

namespace Tests\Feature\Shop;

use App\Livewire\Shop\CourseCatalog;
use App\Models\Course;
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
}
