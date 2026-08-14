<?php

namespace Tests\Unit;

use App\Models\Course;
use App\Models\CourseFaq;
use App\Models\Teacher;
use App\Support\FlagshipLanding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlagshipLandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_matches_three_prod_slugs(): void
    {
        $this->assertSame('kochergina', FlagshipLanding::keyFor(
            Course::factory()->make(['slug' => 'grammatika-po-kocerginoi-gr61', 'title' => 'Грамматика'])
        ));
        $this->assertSame('start_chteniya', FlagshipLanding::keyFor(
            Course::factory()->make(['slug' => 'start-chteniya', 'title' => 'Старт чтения'])
        ));
        $this->assertSame('buhler', FlagshipLanding::keyFor(
            Course::factory()->make(['slug' => 'grammatika-po-biulleru-gr27', 'title' => 'Грамматика'])
        ));
    }

    public function test_non_flagship_is_null(): void
    {
        $course = Course::factory()->make(['slug' => 'hindi-1-sr800-2026', 'title' => 'Грамматика хинди']);

        $this->assertNull(FlagshipLanding::keyFor($course));
        $this->assertNull(FlagshipLanding::for($course));
    }

    public function test_db_outcomes_win_over_config(): void
    {
        $course = Course::factory()->create([
            'slug' => 'grammatika-po-kocerginoi-gr61',
            'title' => 'Грамматика по Кочергиной гр.61',
            'outcomes' => ['Только из базы'],
        ]);
        $course->load(['faqs', 'teacher']);

        $overlay = FlagshipLanding::for($course);

        $this->assertSame(['Только из базы'], $overlay['outcomes']);
    }

    public function test_empty_row_gets_six_faqs_and_no_invented_2026_buhler_start(): void
    {
        $teacher = Teacher::factory()->create(['name' => 'Гасунс Марцис Юрьевич', 'bio' => null]);
        $course = Course::factory()->create([
            'slug' => 'grammatika-po-biulleru-gr27',
            'title' => 'Грамматика по Бюллеру гр.27',
            'teacher_id' => $teacher->id,
            'outcomes' => null,
            'audience' => null,
        ]);
        $course->load(['faqs', 'teacher']);

        $overlay = FlagshipLanding::for($course);

        $this->assertSame('buhler', $overlay['key']);
        $this->assertGreaterThanOrEqual(6, count($overlay['outcomes']));
        $this->assertGreaterThanOrEqual(6, count($overlay['faqs']));
        $this->assertStringContainsString('осень 2027', $overlay['free_step']['title'].' '.$overlay['free_step']['body'].' '.$overlay['free_step']['eyebrow']);
        $this->assertStringContainsString('2026', $overlay['free_step']['title']);
        $this->assertNotEmpty($overlay['teacher_bio_html']);
        $this->assertStringNotContainsString('набор в 2026', mb_strtolower($overlay['free_step']['body']));
    }

    public function test_filled_teacher_bio_is_not_replaced(): void
    {
        $teacher = Teacher::factory()->create(['name' => 'Гасунс', 'bio' => '<p>Уже есть.</p>']);
        $course = Course::factory()->create([
            'slug' => 'start-chteniya',
            'title' => 'Старт чтения',
            'teacher_id' => $teacher->id,
        ]);
        $course->load(['faqs', 'teacher']);

        $this->assertNull(FlagshipLanding::for($course)['teacher_bio_html']);
    }

    public function test_db_faqs_win_over_config(): void
    {
        $course = Course::factory()->create([
            'slug' => 'start-chteniya',
            'title' => 'Старт чтения',
        ]);
        CourseFaq::create([
            'course_id' => $course->id,
            'question' => 'Только из базы?',
            'answer' => 'Да.',
            'is_visible' => true,
        ]);
        $course->load(['faqs', 'teacher']);

        $overlay = FlagshipLanding::for($course);

        $this->assertCount(1, $overlay['faqs']);
        $this->assertSame('Только из базы?', $overlay['faqs'][0]['q']);
    }
}
