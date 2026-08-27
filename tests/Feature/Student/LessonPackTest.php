<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Models\Category;
use App\Models\Course;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * H3521 — Learn Your Way serving: default-OFF флаг, пак-роут, вкладка урока.
 */
class LessonPackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_flag_off_route_404_and_lesson_tab_absent(): void
    {
        config(['lyw.enabled' => false]);
        [$user, $course, $lesson] = $this->seedSanskritLesson();

        $this->actingAs($user)
            ->get(route('student.lesson.lessonpack', [$course->slug, $lesson->id]))
            ->assertNotFound();

        $lessonHtml = $this->actingAs($user)
            ->get(route('student.lesson', [$course->slug, $lesson->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('data-testid="lyw-lesson-tab"', $lessonHtml);
    }

    public function test_flag_on_serves_base_pack_without_answer_leak(): void
    {
        config(['lyw.enabled' => true]);
        $this->installFixturePacks();
        [$user, $course, $lesson] = $this->seedSanskritLesson();

        $html = $this->actingAs($user)
            ->get(route('student.lesson.lessonpack', [$course->slug, $lesson->id]))
            ->assertOk()
            ->assertSee('data-testid="lyw-pack"', false)
            ->assertSee('Занятие I', false)
            ->assertSee('Сколько рядов (varga) в группе sparśa?', false)
            ->getContent();

        // Квиз виден, ключи ответов — никогда.
        $this->assertStringNotContainsString('answer_index', $html);
        $this->assertStringNotContainsString('answer_keys', $html);
        $this->assertStringContainsString('data-testid="lyw-mindmap"', $html);

        // Вкладка на странице урока появляется только при включённом флаге.
        $this->actingAs($user)
            ->get(route('student.lesson', [$course->slug, $lesson->id]))
            ->assertOk()
            ->assertSee('data-testid="lyw-lesson-tab"', false);
    }

    public function test_flag_on_resolves_profile_params_with_fixture_pack(): void
    {
        config(['lyw.enabled' => true]);
        $this->installFixturePacks();
        [$user, $course, $lesson] = $this->seedSanskritLesson();

        $this->actingAs($user)
            ->get(route('student.lesson.lessonpack', [$course->slug, $lesson->id,
                'level' => 'nol', 'interest' => 'yoga']))
            ->assertOk()
            ->assertSee('data-lyw-profile="nol/yoga"', false)
            ->assertSee('Ваш интерес — йога.', false);

        // Невалидные параметры сворачиваются в базовый профиль, а не в 500/404.
        $this->actingAs($user)
            ->get(route('student.lesson.lessonpack', [$course->slug, $lesson->id,
                'level' => '../../etc', 'interest' => 'drop-table']))
            ->assertOk()
            ->assertSee('data-lyw-profile="base/base"', false);
    }

    public function test_malformed_manifest_returns_clean_404(): void
    {
        config(['lyw.enabled' => true]);
        $root = $this->installFixturePacks();
        file_put_contents(
            $root.'/zan1/base/manifest.json',
            '{"schema": "totally-other-schema", "zan": 1}',
        );
        [$user, $course, $lesson] = $this->seedSanskritLesson();

        $this->actingAs($user)
            ->get(route('student.lesson.lessonpack', [$course->slug, $lesson->id]))
            ->assertNotFound();
    }

    public function test_missing_pack_returns_clean_404(): void
    {
        config(['lyw.enabled' => true]);
        $this->installFixturePacks();
        [$user, $course, $lesson] = $this->seedSanskritLesson();

        // Пак есть только для zan1; zan по умолчанию 1 — удалим базовый манифест.
        unlink($this->packsRoot().'/zan1/base/manifest.json');

        $this->actingAs($user)
            ->get(route('student.lesson.lessonpack', [$course->slug, $lesson->id]))
            ->assertNotFound();
    }

    /**
     * Распаковать закоммиченные фикстуры паков во временный корень packs_path.
     */
    private function installFixturePacks(): string
    {
        $src = base_path('tests/fixtures/lesson_packs');
        $root = sys_get_temp_dir().'/lyw-packs-'.uniqid('', true);
        foreach (['zan1/base', 'zan1/nol/yoga'] as $rel) {
            $dest = $root.'/'.$rel;
            if (! is_dir($dest)) {
                mkdir($dest, 0777, true);
            }
            foreach (['manifest.json', 'personalized_text.md', 'views/mindmap.mmd', 'quizzes.json'] as $f) {
                $from = $src.'/'.$rel.'/'.$f;
                $to = $dest.'/'.$f;
                @mkdir(dirname($to), 0777, true);
                copy($from, $to);
            }
        }
        config(['lyw.packs_path' => $root]);

        return $root;
    }

    private function packsRoot(): string
    {
        return (string) config('lyw.packs_path');
    }

    /**
     * @return array{0: User, 1: Course, 2: Lesson}
     */
    private function seedSanskritLesson(): array
    {
        $sanskrit = Category::factory()->create(['name' => 'Санскрит', 'slug' => 'sanskrit']);
        $course = Course::factory()->create(['title' => 'Санскрит гр. LYW', 'slug' => 'sa-lyw']);
        $course->categories()->attach($sanskrit->id);
        $group = Group::create(['name' => 'Sa-LYW']);
        $course->groups()->attach($group->id);
        $lesson = Lesson::factory()->for($course)->create([
            'title' => 'Занятие 1',
            'sort_order' => 1,
            'block_number' => 1,
            'is_published' => true,
            'is_free' => false,
            'is_preview' => false,
            'attachments' => [],
        ]);
        $user = User::factory()->create();
        $user->groups()->attach($group->id);
        Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 1000,
            'tariff' => 'full',
            'status' => 'paid',
        ]));

        return [$user, $course, $lesson];
    }
}
