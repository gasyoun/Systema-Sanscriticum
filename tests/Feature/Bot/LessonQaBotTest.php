<?php

declare(strict_types=1);

namespace Tests\Feature\Bot;

use App\Jobs\LessonQaAnswerJob;
use App\Models\Course;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\User;
use App\Services\Support\Faq\EmbeddingProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeEmbeddingProvider;
use Tests\TestCase;

/**
 * Этап 4 — вопрос по занятию в кабинетном боте.
 *
 * Пинится не только «работает», но и границы: внешний провайдер не видит текст
 * платного занятия, «позови куратора» по-прежнему выигрывает у автоматики, а
 * при выключенном флаге поведение прежнее.
 */
class LessonQaBotTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT_ID = 550123;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Storage::fake('local');

        config([
            'services.telegram.bot_webhook_secret' => 'test-tg',
            'services.telegram.bot_token' => 'test-token',
            'services.openrouter.api_key' => 'test-openrouter',
            'features.lesson_qa' => true,
            'knowledge.driver' => 'ollama',
            'knowledge.dimensions' => 4,
            'knowledge.embedding_model' => 'test-model',
            'knowledge.lesson.min_score' => 0.0,
        ]);

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-tg');

        $this->app->instance(EmbeddingProvider::class, new FakeEmbeddingProvider(default: [1.0, 0.0, 0.0, 0.0]));
    }

    public function test_student_gets_answer_from_own_lesson_and_openrouter_is_never_called(): void
    {
        [$student, $lesson] = $this->studentWithOpenLesson();
        $this->fakeTelegramAndLocalNode('Шестой класс образует основу прибавлением «а» под ударением.');

        $this->postJson('/api/telegram/webhook', [
            'message' => ['chat' => ['id' => self::CHAT_ID], 'text' => 'что говорили про шестой класс глаголов'],
        ])->assertOk();

        Http::assertSent(function ($request) use ($lesson) {
            if (! str_contains($request->url(), 'api.telegram.org')) {
                return false;
            }
            $text = (string) ($request->data()['text'] ?? '');

            return str_contains($text, 'Шестой класс образует основу')
                && str_contains($text, '00:41')                      // таймкод фрагмента
                && str_contains($text, "/u/{$lesson->id}");          // ссылка на урок в кабинете
        });

        // Текст платного занятия наружу не уходит: локальный узел вместо OpenRouter.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'openrouter.ai'));

        $this->assertDatabaseHas('chat_messages', ['user_id' => $student->id, 'role' => 'bot']);
    }

    public function test_flag_off_keeps_the_previous_behaviour(): void
    {
        config(['features.lesson_qa' => false]);
        [$student] = $this->studentWithOpenLesson();

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => 'Ответ обычного куратора']]],
            ], 200),
            '127.0.0.1:11434/*' => Http::response([], 500),
        ]);

        $this->postJson('/api/telegram/webhook', [
            'message' => ['chat' => ['id' => self::CHAT_ID], 'text' => 'что говорили про шестой класс глаголов'],
        ])->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'openrouter.ai'));
    }

    public function test_call_the_curator_still_wins_over_lesson_answers(): void
    {
        $this->studentWithOpenLesson();
        $this->fakeTelegramAndLocalNode('Не должно быть вызвано');

        $this->postJson('/api/telegram/webhook', [
            'message' => ['chat' => ['id' => self::CHAT_ID], 'text' => 'позови куратора, вопрос по шестому классу'],
        ])->assertOk();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '11434'));
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.telegram.org')
                && str_contains((string) ($request->data()['text'] ?? ''), 'живому куратору');
        });
    }

    public function test_node_down_queues_the_question_instead_of_losing_it(): void
    {
        // Посев ИДЁТ ПЕРВЫМ: Queue::fake() перехватывает и dispatch_sync (он
        // уходит в sync-соединение), поэтому подмена очереди до индексации
        // оставила бы урок без единого фрагмента и тест проверял бы пустоту.
        $this->studentWithOpenLesson();
        Queue::fake();

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
            '127.0.0.1:11434/*' => Http::response([], 500),   // узел лёг
            'openrouter.ai/*' => Http::response(['choices' => [['message' => ['content' => 'внешний ответ']]]], 200),
        ]);

        $this->postJson('/api/telegram/webhook', [
            'message' => ['chat' => ['id' => self::CHAT_ID], 'text' => 'что там было про шестой класс глаголов'],
        ])->assertOk();

        Queue::assertPushed(LessonQaAnswerJob::class);
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.telegram.org')
                && str_contains((string) ($request->data()['text'] ?? ''), 'как только он освободится');
        });
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'openrouter.ai'));
    }

    public function test_lessons_command_lists_only_accessible_lessons(): void
    {
        [$student, $lesson] = $this->studentWithOpenLesson();

        // Урок другого курса, к которому студент отношения не имеет.
        $foreign = Lesson::factory()->for(Course::factory())->create([
            'title' => 'Чужое занятие',
            'is_published' => true,
            'group_id' => null,
        ]);
        $this->putTranscript($foreign, 'Чужой текст.');

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $this->postJson('/api/telegram/webhook', [
            'message' => ['chat' => ['id' => self::CHAT_ID], 'text' => '/уроки'],
        ])->assertOk();

        Http::assertSent(function ($request) use ($lesson) {
            if (! str_contains($request->url(), 'api.telegram.org')) {
                return false;
            }
            $text = (string) ($request->data()['text'] ?? '');

            return str_contains($text, (string) $lesson->title)
                && ! str_contains($text, 'Чужое занятие');
        });
    }

    /** @return array{0: User, 1: Lesson} */
    private function studentWithOpenLesson(): array
    {
        $course = Course::factory()->create();
        $lesson = Lesson::factory()->for($course)->create([
            'title' => 'Кочергина 15 (читка)',
            'is_published' => true,
            'is_free' => true,      // доступ без оплаты — сам гейт проверяется в LessonQaAccessTest
            'group_id' => null,
        ]);
        $this->putTranscript($lesson, 'Шестой класс глаголов образует основу настоящего времени.');

        $group = Group::create(['name' => 'Группа курса']);
        $course->groups()->attach($group->id);

        $student = User::factory()->create(['telegram_id' => self::CHAT_ID]);
        $student->groups()->attach($group->id);

        $this->artisan('knowledge:index-lessons', ['--sync' => true])->assertSuccessful();

        return [$student->refresh(), $lesson->refresh()];
    }

    private function putTranscript(Lesson $lesson, string $text): void
    {
        $words = [];
        $start = 41.0;
        foreach (explode(' ', $text) as $word) {
            $words[] = ['word' => $word, 'punctuated_word' => $word, 'start' => $start, 'end' => $start + 0.4];
            $start += 0.5;
        }

        $path = 'transcripts/lesson-'.$lesson->id.'.json';
        Storage::disk('local')->put($path, json_encode(
            ['results' => ['channels' => [['alternatives' => [['words' => $words]]]]]],
            JSON_UNESCAPED_UNICODE,
        ));
        $lesson->forceFill(['transcript_file' => $path])->save();
    }

    private function fakeTelegramAndLocalNode(string $answer): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
            '127.0.0.1:11434/*' => Http::response([
                'choices' => [['message' => ['content' => $answer]]],
                'model' => 'qwen3:14b',
            ], 200),
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => 'ВНЕШНИЙ ОТВЕТ — не должен понадобиться']]],
            ], 200),
        ]);
    }
}
