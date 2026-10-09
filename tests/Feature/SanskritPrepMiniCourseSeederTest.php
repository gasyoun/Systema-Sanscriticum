<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\CourseQuiz;
use App\Models\CourseQuizQuestion;
use App\Models\Lesson;
use App\Models\Tariff;
use Database\Seeders\SanskritPrepMiniCourseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Сидер мини-курса «Подготовительная группа по санскриту» (перенос бота
 * Senler №1186308): курс + 5 этапов с уроками (контент статей ВК — в
 * content_html) + квиз после каждого этапа + нативные домашки.
 * Повторный запуск обновляет контент и не плодит дубли.
 */
class SanskritPrepMiniCourseSeederTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function seeds_course_with_five_stages_lessons_and_quizzes(): void
    {
        $this->seed(SanskritPrepMiniCourseSeeder::class);

        $course = Course::where('slug', 'podgotovitelnaya-gruppa-sanskrita')->firstOrFail();

        $this->assertTrue($course->is_visible);
        $this->assertTrue($course->is_active);
        $this->assertSame('recorded', $course->format);

        // Бесплатная запись: тариф 0 ₽ + группа курса привязана.
        $this->assertSame(1, $course->groups()->count());
        $tariff = Tariff::where('course_id', $course->id)->where('type', 'full')->firstOrFail();
        $this->assertEquals(0, (float) $tariff->price);
        $this->assertTrue($tariff->is_active);

        // 5 блоков-этапов, у каждого уроки и активный квиз.
        $this->assertSame(5, CourseBlock::where('course_id', $course->id)->count());

        $lessons = Lesson::where('course_id', $course->id)->get();
        $this->assertSame(7, $lessons->count());
        $this->assertTrue($lessons->every(fn (Lesson $l) => $l->is_free && $l->is_published));

        // Контент статей перенесён в богатые тела уроков (content_html).
        $this->assertTrue($lessons->every(fn (Lesson $l) => filled($l->content_html)));
        $bodies = $lessons->pluck('content_html')->implode(' ');
        $this->assertStringContainsString('vkvideo.ru/video-88831040_456239808', $bodies); // вводная лекция
        $this->assertStringContainsString('Ачьюта Палава', $bodies); // каллиграфия
        $this->assertStringContainsString('Уша Рани Санка', $bodies); // рецитация
        $this->assertStringContainsString('máma nā́ma', $bodies); // устный санскрит
        $this->assertStringContainsString('parighaḥ saṃniveśitaḥ', $bodies); // шлока 90

        // Уроки этапов с практикой открывают нативные домашки.
        $this->assertSame(5, Lesson::where('course_id', $course->id)
            ->where('homework_enabled', true)
            ->whereNotNull('homework_prompt')
            ->count());

        // В HTML сохраняется авторская вёрстка (инлайновые стили не вырезаются).
        $hero = Lesson::where('course_id', $course->id)->where('title', 'Урок 2 — Каллиграфия деванагари')->firstOrFail();
        $this->assertStringContainsString('style="', (string) $hero->content_html);
        $this->assertStringContainsString('/images/prep-course/l2-kalligrafiya.jpg', (string) $hero->content_html);

        $quizzes = CourseQuiz::where('course_id', $course->id)->orderBy('block_number')->get();
        $this->assertCount(5, $quizzes);
        $this->assertSame([1, 2, 3, 4, 5], $quizzes->pluck('block_number')->all());
        $this->assertTrue($quizzes->every(fn (CourseQuiz $q) => $q->is_active && $q->questions()->exists()));

        // Вопросы сохраняют правильные индексы внутри границ вариантов.
        CourseQuizQuestion::where('course_quiz_id', $quizzes->first()->id)
            ->get()
            ->each(fn (CourseQuizQuestion $q) => $this->assertLessThan(count($q->options), $q->correct_option));
    }

    /** @test */
    public function seeder_is_idempotent(): void
    {
        $this->seed(SanskritPrepMiniCourseSeeder::class);
        $this->seed(SanskritPrepMiniCourseSeeder::class);

        $course = Course::where('slug', 'podgotovitelnaya-gruppa-sanskrita')->firstOrFail();

        $this->assertSame(1, Course::where('slug', 'podgotovitelnaya-gruppa-sanskrita')->count());
        $this->assertSame(7, Lesson::where('course_id', $course->id)->count());
        $this->assertSame(5, CourseQuiz::where('course_id', $course->id)->count());

        // Вопросы пересоздаются, дубликатов нет.
        $quizOne = CourseQuiz::where('course_id', $course->id)->where('block_number', 1)->firstOrFail();
        $this->assertSame(5, $quizOne->questions()->count());
    }
}
