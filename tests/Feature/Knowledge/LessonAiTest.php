<?php

declare(strict_types=1);

namespace Tests\Feature\Knowledge;

use App\Models\Course;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\User;
use App\Services\Knowledge\LessonAiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ИИ-помощник на странице занятия (features.lesson_ai).
 *
 * Пины контура: флаг ВЫКЛ — 404 и вкладки нет; без доступа к уроку — 404;
 * генерация ходит ТОЛЬКО на локальный узел (рулинг #1633), OpenRouter не
 * трогается; сырой HTML модели в ответ не попадает; лимит и окно узла.
 */
class LessonAiTest extends TestCase
{
    use RefreshDatabase;

    private const NODE = 'http://gpu-node.test:11434';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Carbon::setTestNow(Carbon::parse('2026-11-21 12:00:00', 'Europe/Moscow'));

        config([
            'features.lesson_ai' => true,
            'knowledge.generation_base_url' => self::NODE,
            'knowledge.tunnel_hours' => '09:30-20:30',
            'knowledge.lesson_ai.daily_limit' => 30,
            'services.openrouter.api_key' => 'must-not-be-used',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_flag_off_hides_tab_and_routes(): void
    {
        config(['features.lesson_ai' => false]);
        [$course, $lesson, $student] = $this->openLesson();

        $this->actingAs($student)
            ->get(route('student.lesson', [$course->slug, $lesson->id]))
            ->assertOk()
            ->assertDontSee('data-testid="lesson-ai-tab"', false);

        $this->actingAs($student)
            ->postJson(route('student.lesson.ai', [$course->slug, $lesson->id]), ['task' => 'summary'])
            ->assertNotFound();
    }

    public function test_flag_on_shows_tab_on_lesson_page(): void
    {
        [$course, $lesson, $student] = $this->openLesson();

        $this->actingAs($student)
            ->get(route('student.lesson', [$course->slug, $lesson->id]))
            ->assertOk()
            ->assertSee('data-testid="lesson-ai-tab"', false)
            ->assertSee('js/lesson-ai.js', false);
    }

    public function test_student_without_access_gets_404_and_model_is_not_called(): void
    {
        Http::fake();
        $course = Course::factory()->create();
        $lesson = $this->lessonWithTranscript($course, free: false);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->postJson(route('student.lesson.ai', [$course->slug, $lesson->id]), ['task' => 'summary'])
            ->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_summary_goes_to_local_node_only_and_is_cached_for_the_lesson(): void
    {
        $this->fakeNode("## Кратко\nЗанятие о сандхи.\n\n## Оглавление\n- [00:41] Сандхи гласных");
        [$course, $lesson, $student] = $this->openLesson();

        $res = $this->actingAs($student)
            ->postJson(route('student.lesson.ai', [$course->slug, $lesson->id]), ['task' => 'summary'])
            ->assertOk()
            ->assertJsonPath('status', 'done')
            ->assertJsonPath('task', 'summary');

        $this->assertStringContainsString('data-ts="41"', $res->json('html'), 'таймкод стал кнопкой перехода');
        Http::assertSent(fn (HttpRequest $r): bool => str_starts_with($r->url(), self::NODE));
        Http::assertNotSent(fn (HttpRequest $r): bool => str_contains($r->url(), 'openrouter'));
        Http::assertSentCount(1);

        // Второй студент получает тот же пересказ из кэша занятия — без модели.
        $other = $this->studentInGroupOf($course);
        $this->actingAs($other)
            ->postJson(route('student.lesson.ai', [$course->slug, $lesson->id]), ['task' => 'summary'])
            ->assertOk()
            ->assertJsonPath('cached', true);
        Http::assertSentCount(1);
    }

    public function test_quiz_parses_json_answer(): void
    {
        $this->fakeNode('```json
{"questions":[{"q":"Что такое сандхи?","a":"Слияние звуков на стыке слов.","t":"00:41"},{"q":"Без таймкода","a":"Ответ.","t":"потом"}]}
```');
        [$course, $lesson, $student] = $this->openLesson();

        $res = $this->actingAs($student)
            ->postJson(route('student.lesson.ai', [$course->slug, $lesson->id]), ['task' => 'quiz'])
            ->assertOk()
            ->assertJsonPath('status', 'done')
            ->assertJsonCount(2, 'questions')
            ->assertJsonPath('questions.0.q', 'Что такое сандхи?');

        $this->assertStringContainsString('data-ts="41"', $res->json('questions.0.a_html'));
        $this->assertStringNotContainsString('потом', $res->json('questions.1.a_html'), 'кривой таймкод отброшен');
    }

    public function test_raw_html_from_model_is_stripped(): void
    {
        $this->fakeNode('Объяснение <script>alert(1)</script> и <img src=x onerror=alert(2)> [01:02]');
        [$course, $lesson, $student] = $this->openLesson();

        $html = $this->actingAs($student)
            ->postJson(route('student.lesson.ai', [$course->slug, $lesson->id]), ['task' => 'explain', 'text' => 'сандхи висарги'])
            ->assertOk()
            ->json('html');

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('onerror', $html);
        $this->assertStringContainsString('data-ts="62"', $html);
    }

    public function test_outline_needs_notes(): void
    {
        Http::fake();
        [$course, $lesson, $student] = $this->openLesson();

        $this->actingAs($student)
            ->postJson(route('student.lesson.ai', [$course->slug, $lesson->id]), ['task' => 'outline', 'notes' => 'мало'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'few-notes');

        Http::assertNothingSent();
    }

    public function test_ask_without_matching_fragment_reports_not_found(): void
    {
        Http::fake();
        [$course, $lesson, $student] = $this->openLesson();

        $this->actingAs($student)
            ->postJson(route('student.lesson.ai', [$course->slug, $lesson->id]), ['task' => 'ask', 'question' => 'расписание футбольного чемпионата'])
            ->assertOk()
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('error', 'not-found');

        Http::assertNothingSent();
    }

    public function test_ask_sends_only_matching_fragments_of_this_lesson(): void
    {
        $this->fakeNode('Сандхи — слияние звуков [00:41].');
        [$course, $lesson, $student] = $this->openLesson();

        $this->actingAs($student)
            ->postJson(route('student.lesson.ai', [$course->slug, $lesson->id]), ['task' => 'ask', 'question' => 'что такое сандхи гласных'])
            ->assertOk()
            ->assertJsonPath('status', 'done');

        Http::assertSent(function (HttpRequest $r): bool {
            $user = (string) ($r->data()['messages'][1]['content'] ?? '');

            return str_contains($user, 'сандхи') && str_contains($user, '[00:41]');
        });
    }

    public function test_node_down_reports_offline(): void
    {
        Http::fake([self::NODE.'/*' => Http::response('', 502)]);
        [$course, $lesson, $student] = $this->openLesson();

        $this->actingAs($student)
            ->postJson(route('student.lesson.ai', [$course->slug, $lesson->id]), ['task' => 'explain', 'text' => 'сандхи'])
            ->assertOk()
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('error', 'offline');
    }

    public function test_outside_node_hours_answers_asleep_without_calling_model(): void
    {
        Http::fake();
        Carbon::setTestNow(Carbon::parse('2026-11-21 23:00:00', 'Europe/Moscow'));
        [$course, $lesson, $student] = $this->openLesson();

        $this->actingAs($student)
            ->postJson(route('student.lesson.ai', [$course->slug, $lesson->id]), ['task' => 'explain', 'text' => 'сандхи'])
            ->assertStatus(503)
            ->assertJsonPath('error', 'asleep')
            ->assertJsonPath('hours', '09:30-20:30');

        Http::assertNothingSent();
    }

    public function test_daily_limit(): void
    {
        config(['knowledge.lesson_ai.daily_limit' => 1]);
        $this->fakeNode('Ответ.');
        [$course, $lesson, $student] = $this->openLesson();
        $url = route('student.lesson.ai', [$course->slug, $lesson->id]);

        $this->actingAs($student)->postJson($url, ['task' => 'explain', 'text' => 'сандхи'])->assertOk();
        $this->actingAs($student)->postJson($url, ['task' => 'explain', 'text' => 'сандхи'])
            ->assertStatus(429)
            ->assertJsonPath('error', 'limit');
    }

    public function test_poll_token_is_bound_to_its_owner(): void
    {
        config(['queue.default' => 'null']); // джоба «висит» — токен в pending
        [$course, $lesson, $student] = $this->openLesson();

        $token = $this->actingAs($student)
            ->postJson(route('student.lesson.ai', [$course->slug, $lesson->id]), ['task' => 'explain', 'text' => 'сандхи'])
            ->assertStatus(202)
            ->json('token');

        $this->actingAs($student)
            ->getJson(route('student.lesson.ai.poll', [$course->slug, $lesson->id, $token]))
            ->assertOk()
            ->assertJsonPath('status', 'pending')
            ->assertJsonMissingPath('user_id');

        $other = $this->studentInGroupOf($course);
        $this->actingAs($other)
            ->getJson(route('student.lesson.ai.poll', [$course->slug, $lesson->id, $token]))
            ->assertNotFound();
    }

    public function test_long_transcript_is_summarised_by_parts(): void
    {
        config(['knowledge.lesson_ai.part_chars' => 2000]);
        $this->fakeNode('- [00:41] тезис');
        [$course, $lesson, $student] = $this->openLesson(words: 900);

        $this->actingAs($student)
            ->postJson(route('student.lesson.ai', [$course->slug, $lesson->id]), ['task' => 'summary'])
            ->assertOk()
            ->assertJsonPath('status', 'done');

        $parts = app(LessonAiService::class)->digest($lesson->refresh())['parts'];
        $this->assertGreaterThan(1, $parts);
        Http::assertSentCount($parts + 1); // по вызову на часть + сборка пересказа
    }

    // ---------------------------------------------------------------------

    private function fakeNode(string $answer): void
    {
        Http::fake([self::NODE.'/*' => Http::response([
            'model' => 'qwen3:14b',
            'choices' => [['message' => ['content' => $answer]]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
        ])]);
    }

    /** @return array{0: Course, 1: Lesson, 2: User} */
    private function openLesson(int $words = 0): array
    {
        $course = Course::factory()->create();
        $lesson = $this->lessonWithTranscript($course, free: true, words: $words);

        return [$course, $lesson, $this->studentInGroupOf($course)];
    }

    private function lessonWithTranscript(Course $course, bool $free, int $words = 0): Lesson
    {
        $lesson = Lesson::factory()->for($course)->create([
            'block_number' => 1,
            'block_half' => null,
            'group_id' => null,
            'is_free' => $free,
            'is_preview' => false,
            'is_published' => true,
        ]);

        $text = 'Сегодня разбираем сандхи гласных и висарги.';
        if ($words > 0) {
            $text .= str_repeat(' Повторяем правило слияния звуков на стыке слов.', intdiv($words, 7));
        }
        $path = 'transcripts/lesson-'.$lesson->id.'.json';
        Storage::disk('local')->put($path, json_encode($this->deepgram($text), JSON_UNESCAPED_UNICODE));
        $lesson->forceFill(['transcript_file' => $path])->save();

        return $lesson->refresh();
    }

    private function studentInGroupOf(Course $course): User
    {
        $group = Group::firstOrCreate(['name' => 'Группа '.$course->id]);
        $course->groups()->syncWithoutDetaching([$group->id]);

        $student = User::factory()->create();
        $student->groups()->attach($group->id);

        return $student->refresh();
    }

    /** Минимальный ответ Deepgram: слова со временем, предложения закрыты точкой. */
    private function deepgram(string $text): array
    {
        $words = [];
        $start = 41.0;
        foreach (explode(' ', $text) as $word) {
            $words[] = ['word' => $word, 'punctuated_word' => $word, 'start' => $start, 'end' => $start + 0.4];
            $start += 0.5;
        }

        return ['results' => ['channels' => [['alternatives' => [['words' => $words]]]]]];
    }
}
