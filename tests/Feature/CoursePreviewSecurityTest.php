<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lesson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Безопасность блока «Пример урока» (H154, фаза 2 — highest-risk).
 *
 * Публично доступен ТОЛЬКО помеченный is_preview урок курса — не соседние
 * платные уроки, не уроки другого курса. Обязательная часть DoD.
 */
class CoursePreviewSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function course(array $attrs = []): Course
    {
        return Course::factory()->create($attrs + ['is_visible' => true]);
    }

    public function test_guest_can_watch_the_course_preview_lesson(): void
    {
        $course = $this->course();
        $preview = Lesson::factory()->create([
            'course_id' => $course->id,
            'is_preview' => true,
            'is_published' => true,
            'title' => 'Бесплатный пробный урок',
            'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ',
        ]);

        $response = $this->get(route('shop.course.preview', $course->slug));

        $response->assertOk();
        $response->assertSee('Бесплатный пробный урок');
    }

    public function test_preview_route_404s_when_course_has_no_preview_lesson(): void
    {
        $course = $this->course();
        // Обычный платный урок — НЕ preview: маршрут не должен его отдавать.
        Lesson::factory()->create([
            'course_id' => $course->id,
            'is_preview' => false,
            'is_published' => true,
            'title' => 'Платный урок',
        ]);

        $this->get(route('shop.course.preview', $course->slug))->assertNotFound();
    }

    public function test_guest_cannot_reach_a_non_preview_lesson_player(): void
    {
        $course = $this->course();
        $paid = Lesson::factory()->create([
            'course_id' => $course->id,
            'is_preview' => false,
            'is_published' => true,
        ]);

        // Единственный публичный вход к плееру — /preview (только preview-урок).
        // Прямой заход гостя на приватный плеер кабинета обязан быть закрыт.
        $response = $this->get(route('student.lesson', [$course->slug, $paid->id]));

        $response->assertRedirect(route('login'));
    }

    public function test_preview_of_course_a_never_exposes_course_b_lessons(): void
    {
        $courseA = $this->course();
        $previewA = Lesson::factory()->create([
            'course_id' => $courseA->id,
            'is_preview' => true,
            'is_published' => true,
            'title' => 'Пробный урок курса А',
            'youtube_url' => 'https://youtu.be/aaaaaaaaaaa',
        ]);
        // Приватный платный урок того же курса А — не должен утечь на preview-странице.
        Lesson::factory()->create([
            'course_id' => $courseA->id,
            'is_preview' => false,
            'is_published' => true,
            'title' => 'Секретный платный урок курса А',
        ]);

        $courseB = $this->course();
        Lesson::factory()->create([
            'course_id' => $courseB->id,
            'is_preview' => true,
            'is_published' => true,
            'title' => 'Пробный урок курса Б',
            'youtube_url' => 'https://youtu.be/bbbbbbbbbbb',
        ]);

        $response = $this->get(route('shop.course.preview', $courseA->slug));

        $response->assertOk();
        $response->assertSee('Пробный урок курса А');
        $response->assertDontSee('Пробный урок курса Б');
        $response->assertDontSee('Секретный платный урок курса А');
    }

    public function test_preview_route_404s_for_hidden_course(): void
    {
        $course = $this->course(['is_visible' => false]);
        Lesson::factory()->create([
            'course_id' => $course->id,
            'is_preview' => true,
            'is_published' => true,
        ]);

        $this->get(route('shop.course.preview', $course->slug))->assertNotFound();
    }
}
