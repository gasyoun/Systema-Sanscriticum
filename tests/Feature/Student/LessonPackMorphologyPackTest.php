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
 * H6305 — портативный морфологический пак (kosha morphology-curriculum,
 * урок 1) в существующей схеме lyw-pack-v1 / lyw-quiz-v1.
 *
 * Пак — превью-артефакт: он валидируется СУЩЕСТВУЮЩИМ ридером
 * (LessonPackRepository + student.lesson-learn) без всякого прод-включения
 * и никогда не назначается живым ученикам. Ответы квиза наружу не отдаются.
 */
class LessonPackMorphologyPackTest extends TestCase
{
    use RefreshDatabase;

    private const PACK_DIR = 'resources/data/lesson_packs_morphology/zan1/base';

    private const SNAPSHOT = 'resources/data/lesson_packs_morphology/source_snapshot.json';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_existing_reader_serves_morphology_pack(): void
    {
        config(['lyw.enabled' => true]);
        $this->installPortablePack();
        [$user, $course, $lesson] = $this->seedSanskritLesson();

        $html = $this->actingAs($user)
            ->get(route('student.lesson.lessonpack', [$course->slug, $lesson->id]))
            ->assertOk()
            ->assertSee('data-testid="lyw-pack"', false)
            ->assertSee('Морфология · урок 1 (a-основы)', false)
            ->assertSee('अर्थ (artha) — Accusative singular → ?', false)
            ->assertSee('data-testid="lyw-mindmap"', false)
            ->getContent();

        // Ключи ответов студенту не отдаются никогда.
        $this->assertStringNotContainsString('answer_index', $html);
        $this->assertStringNotContainsString('answer_keys', $html);
    }

    public function test_pack_items_are_pinned_attested_source_rows(): void
    {
        $quizzes = $this->readJson(base_path(self::PACK_DIR.'/quizzes.json'));
        $snapshot = $this->readJson(base_path(self::SNAPSHOT));

        $this->assertSame('lyw-quiz-v1', $quizzes['schema']);
        $items = $quizzes['items'];
        $this->assertCount(20, $items);

        $rows = collect($snapshot['rows'])
            ->keyBy(fn (array $row): string => $row['drill_row_verbatim']['id']);

        $ids = [];
        foreach ($items as $item) {
            $ids[] = $item['id'];

            // Форма предмета — ровно шесть ключей схемы lyw-quiz-v1.
            $this->assertSame(
                ['id', 'type', 'concept', 'prompt', 'options', 'answer_index'],
                array_keys($item),
            );
            $this->assertSame('mcq', $item['type']);
            $this->assertCount(4, $item['options']);
            $this->assertIsInt($item['answer_index']);
            $this->assertGreaterThanOrEqual(0, $item['answer_index']);
            $this->assertLessThanOrEqual(3, $item['answer_index']);

            // Трассировка: attested=true + корпусный локус + зафиксированная строка источника.
            $trace = $quizzes['source_trace'][$item['id']];
            $this->assertTrue($trace['attested']);
            $this->assertStringStartsWith('dcs:', $trace['evidence']);
            $this->assertSame($item['id'], $trace['source_row_id']);

            // Ответы равны ответам источника, порядок опций не менялся.
            $source = $rows->get($item['id'])['drill_row_verbatim'];
            $this->assertNotNull($source, "snapshot row missing for {$item['id']}");
            $this->assertSame($source['choices'], $item['options']);
            $this->assertSame($source['answer'], $item['options'][$item['answer_index']]);

            // Каждая строка трассировки ссылается на урок 1 с attested-клетками.
            foreach ($trace['curriculum_rows'] as $curriculum) {
                $this->assertSame(1, $curriculum['lesson']);
                $this->assertGreaterThanOrEqual(1, $curriculum['n_attested_cells']);
            }
        }

        $this->assertCount(20, array_unique($ids));
    }

    public function test_manifest_pins_source_hashes_license_and_teacher_sample(): void
    {
        $manifest = $this->readJson(base_path(self::PACK_DIR.'/manifest.json'));
        $quizzes = $this->readJson(base_path(self::PACK_DIR.'/quizzes.json'));
        $snapshot = $this->readJson(base_path(self::SNAPSHOT));

        $this->assertSame('lyw-pack-v1', $manifest['schema']);
        $this->assertSame(1, $manifest['zan']);
        $this->assertSame(['level' => 'base', 'interest' => 'base'], $manifest['profile']);
        $this->assertTrue($manifest['source']['attested']);

        // Источник закреплён хэшами и ревизией kosha.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $snapshot['kosha_commit']);
        $this->assertSame($snapshot['kosha_commit'], $manifest['source']['kosha_commit']);
        foreach (['morphology_curriculum_sha256', 'drills_sha256'] as $field) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $snapshot[$field]);
            $this->assertSame($snapshot[$field], $manifest['source'][$field]);
        }

        // Лицензионная атрибуция и в манифесте, и в квизах.
        $this->assertStringContainsString('CC BY-SA 4.0', $manifest['source']['license']);
        $this->assertStringContainsString('CC BY-SA 4.0', $quizzes['license']);

        // Учительская выборка — 5 предметов, укладывается в 10 минут обзора.
        $this->assertCount(5, $manifest['teacher_sample']);
        $packIds = array_column($quizzes['items'], 'id');
        foreach ($manifest['teacher_sample'] as $sampleId) {
            $this->assertContains($sampleId, $packIds);
        }

        // Каждый вопрос буквально вшит в авторский текст (паттерн H3521).
        $text = (string) file_get_contents(base_path(self::PACK_DIR.'/personalized_text.md'));
        foreach ($quizzes['items'] as $item) {
            $this->assertStringContainsString($item['prompt'], $text);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * Распаковать закоммиченный портативный пак во временный packs_path —
     * ровно то, что делает контент-выкладка в проде, без изменения конфигов.
     */
    private function installPortablePack(): string
    {
        $src = base_path(self::PACK_DIR);
        $root = sys_get_temp_dir().'/lyw-morph-packs-'.uniqid('', true);
        $dest = $root.'/zan1/base';
        mkdir($dest.'/views', 0777, true);
        foreach (['manifest.json', 'personalized_text.md', 'quizzes.json', 'views/mindmap.mmd'] as $f) {
            copy($src.'/'.$f, $dest.'/'.$f);
        }
        config(['lyw.packs_path' => $root]);

        return $root;
    }

    /**
     * @return array{0: User, 1: Course, 2: Lesson}
     */
    private function seedSanskritLesson(): array
    {
        $sanskrit = Category::factory()->create(['name' => 'Санскрит', 'slug' => 'sanskrit']);
        $course = Course::factory()->create(['title' => 'Санскрит гр. морфология', 'slug' => 'sa-morph']);
        $course->categories()->attach($sanskrit->id);
        $group = Group::create(['name' => 'Sa-Morph']);
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
