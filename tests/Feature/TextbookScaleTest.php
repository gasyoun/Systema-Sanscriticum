<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lesson;
use App\Services\Schedule\TextbookScale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TextbookScaleTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function parses_chitka_proverka_and_multi_items_from_title(): void
    {
        $items = TextbookScale::parseTitle('Кочергина 7 (читка), Эмено 1 (начало)');
        $this->assertSame('kochergina', $items[0]['family']);
        $this->assertSame(['kochergina', 'emeno'], array_column($items, 'family'));
        $this->assertSame('chitka', $items[0]['kind']);
        $this->assertSame(7, $items[0]['lesson']);

        // Формат живых записей: «Кочергина N» (манифест-формат завершённых
        // потоков «Занятие XIV» — фаза 2, отдельный парсер).
        $items = TextbookScale::parseTitle('Кочергина 14 (проверка), Кочергина 15 (читка)');
        $this->assertSame(14, $items[0]['lesson']);
        $this->assertSame('proverka', $items[0]['kind']);
        $this->assertSame(15, $items[1]['lesson']);
        $this->assertSame('chitka', $items[1]['kind']);

        // Два урока за одно наше занятие — конец книги (живой формат).
        $items = TextbookScale::parseTitle('Кочергина 19 (читка), Кочергина 20 (читка)');
        $this->assertSame([19, 20], array_column($items, 'lesson'));

        // Урок канвы с не-читка/не-проверка пометкой → kind drugoje.
        $items = TextbookScale::parseTitle('Кочергина 6 (лигатуры)');
        $this->assertSame(6, $items[0]['lesson']);
        $this->assertSame('drugoje', $items[0]['kind']);
    }

    /** @test */
    public function non_canvas_titles_parse_to_nothing(): void
    {
        $this->assertSame([], TextbookScale::parseTitle('Грамматика санскрита с нуля №57 (#11, 28.03.26)'));
        $this->assertSame([], TextbookScale::parseTitle(''));
    }

    /** @test */
    public function cursor_moves_only_on_chitka(): void
    {
        $course = Course::factory()->create();
        $mk = fn (string $title, ?int $tl = null) => Lesson::create([
            'title' => $title,
            'course_id' => $course->id,
            'lesson_date' => '2026-09-01 00:00:00',
            'textbook_lesson' => $tl,
        ]);

        $mk('Кочергина 3 (проверка)');
        $mk('Кочергина 4 (читка)');
        $mk('Кочергина 5 (проверка), Кочергина 6 (читка)');

        $this->assertSame(6, TextbookScale::cursor(Lesson::where('course_id', $course->id)->get()));
    }

    /** @test */
    public function explicit_textbook_lesson_column_wins_for_kochergina(): void
    {
        $course = Course::factory()->create();
        Lesson::create([
            'title' => 'Кочергина 3 (читка)',
            'course_id' => $course->id,
            'lesson_date' => '2026-09-01 00:00:00',
            'textbook_lesson' => 5, // куратор вручную поправил
        ]);

        $this->assertSame(5, TextbookScale::cursor(Lesson::where('course_id', $course->id)->get()));
    }

    /** @test */
    public function lesson_weights_count_sessions_per_urok(): void
    {
        $course = Course::factory()->create();
        foreach ([
            'Кочергина 3 (читка)',
            'Кочергина 3 (проверка)', // урок 3 = 2 наших занятия (середина тяжёлая)
            'Кочергина 4 (читка), Кочергина 5 (читка)', // конец: 2 урока за 1 занятие
        ] as $title) {
            Lesson::create(['title' => $title, 'course_id' => $course->id, 'lesson_date' => '2026-09-01 00:00:00']);
        }

        $w = TextbookScale::lessonWeights(Lesson::where('course_id', $course->id)->get());
        $this->assertSame(2, $w[3]);
        $this->assertSame(1, $w[4]);
        $this->assertSame(1, $w[5]);
    }

    /** @test */
    public function projection_uses_recent_lesson_weights(): void
    {
        $course = Course::factory()->create();
        foreach (['Кочергина 38 (читка)', 'Кочергина 38 (проверка)', 'Кочергина 39 (читка)'] as $title) {
            Lesson::create(['title' => $title, 'course_id' => $course->id, 'lesson_date' => '2026-09-01 00:00:00']);
        }
        $lessons = Lesson::where('course_id', $course->id)->get();

        // курсор 39, осталось 1 урок; последние 2 урока = 3 наших занятия → ≈ 2 занятия
        $p = TextbookScale::projection($lessons, 39, 40);
        $this->assertSame(1, $p['remaining_lessons']);
        $this->assertSame(2, $p['recent_lessons']);
        $this->assertSame(3, $p['recent_sessions']);
        $this->assertSame(2, $p['projection_sessions']);

        $this->assertNull(TextbookScale::projection($lessons, 40, 40)); // учебник пройден
    }

    /** @test */
    public function lag_is_cursor_difference_to_family_median(): void
    {
        // Медиана [2,4,6,8] = 5 → lag(6) = +1.
        $this->assertSame(1, TextbookScale::lag(6, [1 => 2, 2 => 4, 3 => 6, 4 => 8]));
        // Медиана [2,4,6] = 4 → lag(2) = -2.
        $this->assertSame(-2, TextbookScale::lag(2, [1 => 2, 2 => 4, 3 => 6]));
        // Пустое семейство → 0.
        $this->assertSame(0, TextbookScale::lag(5, []));
    }
}
