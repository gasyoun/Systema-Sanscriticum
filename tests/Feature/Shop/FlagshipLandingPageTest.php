<?php

namespace Tests\Feature\Shop;

use App\Models\Course;
use App\Models\CourseFaq;
use App\Models\Group;
use App\Models\Schedule;
use App\Models\Teacher;
use App\Support\FlagshipLanding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * H2761: three Gasuns flagships show thematic syllabus, free step, teacher, FAQ.
 */
class FlagshipLandingPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-15 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_three_flagship_pages_show_the_four_sections(): void
    {
        $teacher = Teacher::factory()->create([
            'name' => 'Гасунс Марцис Юрьевич',
            'bio' => null,
        ]);

        foreach (FlagshipLanding::SMOKE_SLUGS as $key => $slug) {
            $title = match ($key) {
                'kochergina' => 'Грамматика по Кочергиной гр.61',
                'start_chteniya' => 'Старт чтения',
                'buhler' => 'Грамматика по Бюллеру гр.27',
            };
            $course = Course::factory()->create([
                'slug' => $slug,
                'title' => $title,
                'is_visible' => true,
                'teacher_id' => $teacher->id,
                'outcomes' => null,
                'audience' => null,
            ]);
            $this->attachUpcomingSession($course, $title);

            $response = $this->get(route('shop.course.show', $slug));
            $response->assertOk();

            $html = $response->getContent();
            $this->assertStringContainsString('id="chemu-nauchites"', $html, $slug.' syllabus');
            $this->assertStringContainsString('Чему вы научитесь', $html, $slug);
            $this->assertStringContainsString('id="flagship-free-step"', $html, $slug.' free step');
            $this->assertStringContainsString('id="teachers"', $html, $slug.' teacher');
            $this->assertStringContainsString('Гасунс Марцис Юрьевич', $html, $slug);
            $this->assertStringContainsString('id="course-faq"', $html, $slug.' faq');
            $this->assertStringContainsString('Частые вопросы', $html, $slug);
            $this->assertStringContainsString('Для кого этот курс', $html, $slug);
            $this->assertStringContainsString('Кому не подходит', $html, $slug);
            $this->assertStringContainsString('Ближайшие занятия', $html, $slug);
            $this->assertStringNotContainsString('#1 15.07.26', $html, $slug.' must not use date-as-syllabus');
        }
    }

    public function test_kochergina_syllabus_is_thematic_not_a_date_list(): void
    {
        $this->makeFlagship('kochergina');

        $response = $this->get(route('shop.course.show', FlagshipLanding::SMOKE_SLUGS['kochergina']));
        $response->assertOk();
        $response->assertSee('Читать и писать деванагари с нуля', false);
        $response->assertSee('Перейти к углублённому курсу по Бюлеру', false);
        $response->assertDontSee('27.07.26', false);
    }

    public function test_buhler_free_step_names_autumn_2027_and_not_a_2026_intake(): void
    {
        $this->makeFlagship('buhler');

        $response = $this->get(route('shop.course.show', FlagshipLanding::SMOKE_SLUGS['buhler']));
        $response->assertOk();
        $response->assertSee('осень 2027', false);
        $response->assertSee('Нового старта в 2026 году нет', false);
        $response->assertSee('"teaches"', false);
        $this->assertStringNotContainsString('набор 2026', $response->getContent());
    }

    public function test_start_chteniya_free_step_is_evergreen(): void
    {
        $this->makeFlagship('start_chteniya');

        $response = $this->get(route('shop.course.show', FlagshipLanding::SMOKE_SLUGS['start_chteniya']));
        $response->assertOk();
        $response->assertSee('data-free-step-kind="evergreen"', false);
        $response->assertSee('Открытый отрывок', false);
        $response->assertSee(route('reading.kosha-demo'), false);
    }

    public function test_filled_db_fields_are_not_replaced(): void
    {
        $teacher = Teacher::factory()->create([
            'name' => 'Гасунс Марцис Юрьевич',
            'bio' => '<p>Биография из админки.</p>',
        ]);
        Course::factory()->create([
            'slug' => FlagshipLanding::SMOKE_SLUGS['kochergina'],
            'title' => 'Грамматика по Кочергиной гр.61',
            'is_visible' => true,
            'teacher_id' => $teacher->id,
            'outcomes' => ['Только из админки'],
            'audience' => ['Аудитория из админки'],
        ]);
        $course = Course::query()->where('slug', FlagshipLanding::SMOKE_SLUGS['kochergina'])->first();
        CourseFaq::create([
            'course_id' => $course->id,
            'question' => 'Вопрос из админки?',
            'answer' => 'Ответ из админки.',
            'is_visible' => true,
        ]);

        $response = $this->get(route('shop.course.show', FlagshipLanding::SMOKE_SLUGS['kochergina']));
        $response->assertOk();
        $response->assertSee('Только из админки', false);
        $response->assertSee('Аудитория из админки', false);
        $response->assertSee('Вопрос из админки?', false);
        $response->assertSee('Биография из админки.', false);
        $response->assertDontSee('Читать и писать деванагари с нуля', false);
        $response->assertDontSee('к.ф.н. Марцис Юрьевич Гасунс — санскритолог', false);
    }

    public function test_ordinary_course_does_not_get_flagship_overlay(): void
    {
        Course::factory()->create([
            'slug' => 'hindi-1-sr800-2026',
            'title' => 'Грамматика хинди гр. 1',
            'is_visible' => true,
        ]);

        $response = $this->get(route('shop.course.show', 'hindi-1-sr800-2026'));
        $response->assertOk();
        $response->assertDontSee('id="chemu-nauchites"', false);
        $response->assertDontSee('id="flagship-free-step"', false);
        $response->assertDontSee('id="course-faq"', false);
        $response->assertDontSee('Чему вы научитесь', false);
    }

    private function makeFlagship(string $key): Course
    {
        $teacher = Teacher::factory()->create([
            'name' => 'Гасунс Марцис Юрьевич',
            'bio' => null,
        ]);
        $slug = FlagshipLanding::SMOKE_SLUGS[$key];
        $title = match ($key) {
            'kochergina' => 'Грамматика по Кочергиной гр.61',
            'start_chteniya' => 'Старт чтения',
            'buhler' => 'Грамматика по Бюллеру гр.27',
        };

        $course = Course::factory()->create([
            'slug' => $slug,
            'title' => $title,
            'is_visible' => true,
            'teacher_id' => $teacher->id,
            'outcomes' => null,
            'audience' => null,
        ]);
        $this->attachUpcomingSession($course, $title);

        return $course;
    }

    private function attachUpcomingSession(Course $course, string $title): void
    {
        $group = Group::factory()->create();
        $course->groups()->attach($group->id);
        Schedule::create([
            'course_id' => $course->id,
            'group_id' => $group->id,
            'title' => $title,
            'start' => now()->addDays(3),
            'end' => now()->addDays(3)->addHours(2),
        ]);
    }
}
