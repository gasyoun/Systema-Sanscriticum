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
 * Рендер продающей страницы курса (H154, фазы 3/5): новые блоки видны при
 * заполненных данных и скрыты при пустых; SEO-фолбэки meta.
 */
class CourseLandingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_course_renders_without_landing_blocks(): void
    {
        $course = Course::factory()->create([
            'is_visible' => true,
            'description' => 'Короткое описание курса.',
        ]);

        $response = $this->get(route('shop.course.show', $course->slug));

        $response->assertOk();
        // Пустые блоки скрыты.
        $response->assertDontSee('Для кого этот курс');
        $response->assertDontSee('Чему вы научитесь');
        $response->assertDontSee('Пример урока');
        $response->assertDontSee('Отзывы учеников');
        $response->assertDontSee('Частые вопросы');
        // Статичные блоки на месте — страница не сломана.
        $response->assertSee('Как проходит оплата');
        $response->assertSee('Технические требования');
    }

    public function test_filled_course_shows_all_landing_blocks(): void
    {
        $teacher = Teacher::create(['name' => 'Мария Учитель', 'bio' => 'Преподаёт санскрит 15 лет.']);
        $course = Course::factory()->create([
            'is_visible' => true,
            'teacher_id' => $teacher->id,
            'audience' => ['Начинающие санскритологи'],
            'outcomes' => ['Читать деванагари бегло'],
            'description' => 'Описание.',
        ]);

        Lesson::factory()->create([
            'course_id' => $course->id,
            'is_preview' => true,
            'is_published' => true,
            'title' => 'Вводный урок',
            'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ',
        ]);

        CourseFaq::create(['course_id' => $course->id, 'question' => 'Нужна ли подготовка?', 'answer' => 'Нет.']);

        $t = Testimonial::create(['author_name' => 'Иван', 'body' => 'Отличный курс!']);
        $course->testimonials()->attach($t->id, ['sort_order' => 0]);

        $response = $this->get(route('shop.course.show', $course->slug));

        $response->assertOk();
        $response->assertSee('Для кого этот курс');
        $response->assertSee('Начинающие санскритологи');
        $response->assertSee('Чему вы научитесь');
        $response->assertSee('Читать деванагари бегло');
        $response->assertSee('Пример урока');
        $response->assertSee('Смотреть пробный урок');
        $response->assertSee('Мария Учитель');
        $response->assertSee('Отзывы учеников');
        $response->assertSee('Отличный курс!');
        $response->assertSee('Частые вопросы');
        $response->assertSee('Нужна ли подготовка?');
    }

    public function test_meta_title_and_description_fall_back_to_course_fields(): void
    {
        $course = Course::factory()->create([
            'is_visible' => true,
            'title' => 'Грамматика санскрита',
            'description' => '<p>Полный курс грамматики с нуля.</p>',
        ]);

        $response = $this->get(route('shop.course.show', $course->slug));

        $response->assertSee('<title>Грамматика санскрита | ОРС</title>', false);
        $response->assertSee('Полный курс грамматики с нуля.', false);
    }

    public function test_meta_fields_override_when_present(): void
    {
        $course = Course::factory()->create([
            'is_visible' => true,
            'title' => 'Грамматика санскрита',
            'meta_title' => 'Учить санскрит онлайн',
            'meta_description' => 'Лучший курс санскрита.',
        ]);

        $response = $this->get(route('shop.course.show', $course->slug));

        $response->assertSee('<title>Учить санскрит онлайн | ОРС</title>', false);
        $response->assertSee('content="Лучший курс санскрита."', false);
    }

    /**
     * Правило «1–2 конкретных отзыва, не стена» (план 10-11-2026):
     * на лендинге максимум 2 карточки, остальные — по ссылке в библиотеку.
     */
    public function test_landing_shows_max_two_testimonials_with_library_link(): void
    {
        $course = Course::factory()->create(['is_visible' => true]);
        foreach ([0, 1, 2] as $i) {
            $t = Testimonial::create([
                'author_name' => 'Отзывчик '.$i,
                'body' => 'Текст отзыва номер '.$i,
            ]);
            $course->testimonials()->attach($t->id, ['sort_order' => $i]);
        }

        $response = $this->get(route('shop.course.show', $course->slug));

        $response->assertOk();
        // Первые два (по sort_order) — на странице.
        $response->assertSee('Текст отзыва номер 0');
        $response->assertSee('Текст отзыва номер 1');
        // Третий — только в библиотеке.
        $response->assertDontSee('Текст отзыва номер 2');
        $response->assertSee('Все отзывы (3)');
        $response->assertSee(route('shop.testimonials.library'), false);
    }

    public function test_landing_hides_library_link_when_two_or_fewer_testimonials(): void
    {
        $course = Course::factory()->create(['is_visible' => true]);
        foreach ([0, 1] as $i) {
            $t = Testimonial::create([
                'author_name' => 'Отзывчик '.$i,
                'body' => 'Короткий отзыв '.$i,
            ]);
            $course->testimonials()->attach($t->id, ['sort_order' => $i]);
        }

        $response = $this->get(route('shop.course.show', $course->slug));

        $response->assertOk();
        $response->assertSee('Короткий отзыв 0');
        $response->assertSee('Короткий отзыв 1');
        $response->assertDontSee('Все отзывы');
    }
}
