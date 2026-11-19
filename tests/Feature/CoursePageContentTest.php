<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Support\CoursePageContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H6580: контент страницы /k/<slug> из T1-анонса — контент-JSON
 * resources/data/course-page-content.json (ключ = slug), канон таблицы —
 * docs/COURSE_PAGE_ANONS_MAPPING_2026-10-13.md. Хардкод фраз в ассертах —
 * сознательный: это публичный текст на странице продаж; правка JSON без
 * правки теста должна ломать тест.
 */
class CoursePageContentTest extends TestCase
{
    use RefreshDatabase;

    public function test_anons_description_renders_when_db_description_empty(): void
    {
        Course::factory()->create(['slug' => 'filosofiya-sankhi', 'description' => null]);

        $this->get(route('shop.course.show', 'filosofiya-sankhi'))
            ->assertOk()
            ->assertSee('data-testid="course-anons-description"', false)
            ->assertSee('Зачем изучать санкхью', false)
            ->assertSee('Это и есть философия Индии!', false)
            ->assertSee('ключ ко всей философской традиции Индии', false)
            ->assertDontSee('Подробное описание курса скоро появится');
    }

    public function test_post_logistics_numbers_are_not_transferred(): void
    {
        Course::factory()->create(['slug' => 'filosofiya-sankhi', 'description' => null]);

        $response = $this->get(route('shop.course.show', 'filosofiya-sankhi'));
        $response->assertOk();

        $anonsBlock = substr($response->content(), (int) strpos($response->content(), 'course-anons-description'), 6000);
        $this->assertStringNotContainsString('6 000', $anonsBlock);
        $this->assertStringNotContainsString('нужно 8 намерений', $anonsBlock);
        $this->assertStringNotContainsString('Онлайн-группа, 16 занятий', $anonsBlock);
    }

    public function test_db_description_wins_over_anons_content(): void
    {
        Course::factory()->create([
            'slug' => 'filosofiya-sankhi',
            'description' => '<p>Каноническое описание из БД.</p>',
        ]);

        $this->get(route('shop.course.show', 'filosofiya-sankhi'))
            ->assertOk()
            ->assertSee('Каноническое описание из БД.')
            ->assertDontSee('data-testid="course-anons-description"', false);
    }

    public function test_unlisted_slug_keeps_placeholder(): void
    {
        $course = Course::factory()->create(['description' => null]);

        $this->get(route('shop.course.show', $course->slug))
            ->assertOk()
            ->assertSee('Подробное описание курса скоро появится')
            ->assertDontSee('data-testid="course-anons-description"', false)
            ->assertDontSee('data-testid="course-anons-video"', false)
            ->assertDontSee('data-testid="course-anons-program"', false);
    }

    public function test_support_fields_shape_for_pilot_slug(): void
    {
        $content = CoursePageContent::for('filosofiya-sankhi');

        $this->assertNotNull($content);
        $this->assertStringContainsString('<p>', (string) $content['description_html']);
        $this->assertSame([], $content['program_bullets']);
        $this->assertSame([], $content['video_links']);
        $this->assertNull($content['prerequisites']);
        $this->assertSame([], $content['faqs']);
    }

    public function test_support_returns_null_for_unknown_slug_and_sanitizes_html(): void
    {
        $this->assertNull(CoursePageContent::for('no-such-course'));

        // Санитизация контура H2896: script не доходит до страницы даже из JSON.
        $this->assertStringNotContainsString('<script', (string) CoursePageContent::for('filosofiya-sankhi')['description_html']);
    }
}
