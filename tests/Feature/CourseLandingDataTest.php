<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseFaq;
use App\Models\Lesson;
use App\Models\Teacher;
use App\Models\Testimonial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Слой данных продающей страницы курса (H154, фаза 1): реляции, каст массивов,
 * открытость preview-урока. Не трогает логику ключей full/block_N/block_N_hH.
 */
class CourseLandingDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_audience_and_outcomes_round_trip_as_arrays(): void
    {
        $course = Course::factory()->create([
            'audience' => ['Новички', 'Санскритологи'],
            'outcomes' => ['Читать деванагари', 'Разбирать сандхи'],
        ]);

        $fresh = $course->fresh();

        $this->assertSame(['Новички', 'Санскритологи'], $fresh->audience);
        $this->assertSame(['Читать деванагари', 'Разбирать сандхи'], $fresh->outcomes);
    }

    public function test_faqs_relation_is_ordered_by_sort_order(): void
    {
        $course = Course::factory()->create();
        CourseFaq::create(['course_id' => $course->id, 'question' => 'B', 'answer' => 'b', 'sort_order' => 2]);
        CourseFaq::create(['course_id' => $course->id, 'question' => 'A', 'answer' => 'a', 'sort_order' => 1]);

        $this->assertSame(['A', 'B'], $course->faqs->pluck('question')->all());
    }

    public function test_testimonials_pivot_orders_by_sort_order(): void
    {
        $course = Course::factory()->create();
        $first = Testimonial::create(['author_name' => 'Первый', 'body' => '...']);
        $second = Testimonial::create(['author_name' => 'Второй', 'body' => '...']);

        $course->testimonials()->attach($second->id, ['sort_order' => 1]);
        $course->testimonials()->attach($first->id, ['sort_order' => 0]);

        $this->assertSame(['Первый', 'Второй'], $course->testimonials->pluck('author_name')->all());
    }

    public function test_preview_lesson_relation_returns_the_flagged_lesson(): void
    {
        $course = Course::factory()->create();
        Lesson::factory()->create(['course_id' => $course->id, 'is_preview' => false]);
        $preview = Lesson::factory()->create(['course_id' => $course->id, 'is_preview' => true]);

        $this->assertNotNull($course->previewLesson);
        $this->assertSame($preview->id, $course->previewLesson->id);
    }

    public function test_preview_lesson_is_unlocked_for_everyone_without_any_key(): void
    {
        $course = Course::factory()->create();
        $preview = Lesson::factory()->create(['course_id' => $course->id, 'block_number' => 3, 'is_preview' => true]);
        $paid = Lesson::factory()->create(['course_id' => $course->id, 'block_number' => 3, 'is_preview' => false]);

        // Гость (нет оплаченных ключей) — preview открыт, платный урок закрыт.
        $this->assertTrue($preview->isUnlockedBy([]));
        $this->assertFalse($paid->isUnlockedBy([]));

        // Логика платных ключей не сломана.
        $this->assertTrue($paid->isUnlockedBy(['block_3']));
    }

    public function test_teacher_photo_path_is_fillable(): void
    {
        $teacher = Teacher::create(['name' => 'Учитель', 'photo_path' => 'teachers/x.jpg']);

        $this->assertSame('teachers/x.jpg', $teacher->fresh()->photo_path);
    }
}
