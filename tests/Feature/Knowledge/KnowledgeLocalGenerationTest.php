<?php

declare(strict_types=1);

namespace Tests\Feature\Knowledge;

use App\Models\User;
use App\Services\Bot\CuratorAi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * H3234 (issue #1633 этап 6): локальная генерация. Флаг ON → CuratorAi
 * ходит ТОЛЬКО в туннель (127.0.0.1:11434, knowledge.generation_model) —
 * Ollama отдаёт OpenAI-совместимый /v1/chat/completions, смена провайдера =
 * смена конфига, без правки класса. Узел недоступен → null и
 * детерминированная деградация у вызывающих; ОТКАТ В OPENROUTER/DEEPSEEK
 * ЗАПРЕЩЁН текстом issue — закреплено тестом.
 */
class KnowledgeLocalGenerationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'Локал',
            'email' => 'local'.uniqid().'@example.test',
            'password' => 'password',
        ]);

        config([
            'features.bot_local_generation' => true,
            'services.openrouter.api_key' => 'test-key',
            'services.openrouter.base_url' => 'https://openrouter.test/api/v1',
            'services.openrouter.model' => 'deepseek/deepseek-v4-flash',
            'knowledge.driver' => 'ollama',
            'knowledge.base_url' => 'http://127.0.0.1:11434',
            'knowledge.generation_model' => 'qwen3:14b',
            'knowledge.generation_timeout' => 120,
        ]);

        Cache::flush();
    }

    public function test_local_node_answers_and_openrouter_is_never_called(): void
    {
        $openrouterCalled = false;
        Http::fake(function ($request) use (&$openrouterCalled) {
            if (str_contains((string) $request->url(), 'openrouter.test')) {
                $openrouterCalled = true;

                return Http::response([
                    'choices' => [['message' => ['content' => 'DEEPSEEK-УТЕЧКА']]],
                ]);
            }

            return Http::response([
                'choices' => [['message' => ['content' => 'ОТВЕТ-ЛОКАЛЬНЫЙ']]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
                'model' => 'qwen3:14b',
            ]);
        });

        $answer = app(CuratorAi::class)->reply($this->user, 'как оплатить курс?');

        $this->assertSame('ОТВЕТ-ЛОКАЛЬНЫЙ', $answer);
        $this->assertFalse($openrouterCalled, 'локальный режим не ходит наружу — приватность без оговорок');
    }

    public function test_tunnel_down_degrades_to_null_without_external_fallback(): void
    {
        $openrouterCalled = false;
        Http::fake(function ($request) use (&$openrouterCalled) {
            if (str_contains((string) $request->url(), 'openrouter.test')) {
                $openrouterCalled = true;

                return Http::response([
                    'choices' => [['message' => ['content' => 'DEEPSEEK-УТЕЧКА']]],
                ]);
            }

            return Http::response('tunnel down', 500);
        });

        $answer = app(CuratorAi::class)->reply($this->user, 'как оплатить курс?');

        $this->assertNull($answer, 'туннель умер → null → контроллеры отвечают детерминированно');
        $this->assertFalse($openrouterCalled, 'откат в DeepSeek запрещён: «приватность, пока работает туннель» — не приватность');
    }

    public function test_local_endpoint_is_openai_compatible_chat_completions(): void
    {
        Http::fake([
            '127.0.0.1:11434/*' => Http::response([
                'choices' => [['message' => ['content' => 'ОК']]],
            ]),
        ]);

        app(CuratorAi::class)->reply($this->user, 'привет');

        Http::assertSent(function ($request): bool {
            return str_contains((string) $request->url(), '127.0.0.1:11434/v1/chat/completions')
                && ($request->data()['model'] ?? null) === 'qwen3:14b'
                && ($request->data()['messages'][0]['role'] ?? null) === 'system';
        });
    }

    public function test_flag_off_keeps_the_openrouter_path(): void
    {
        config(['features.bot_local_generation' => false]);

        $localCalled = false;
        Http::fake(function ($request) use (&$localCalled) {
            if (str_contains((string) $request->url(), '127.0.0.1:11434')) {
                $localCalled = true;

                return Http::response(['choices' => [['message' => ['content' => 'ЛОКАЛ']]]]);
            }

            return Http::response([
                'choices' => [['message' => ['content' => 'ОТВЕТ-ONLINE']]],
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
                'model' => 'deepseek/deepseek-v4-flash',
            ]);
        });

        $answer = app(CuratorAi::class)->reply($this->user, 'вопрос');

        $this->assertSame('ОТВЕТ-ONLINE', $answer);
        $this->assertFalse($localCalled, 'флаг выключен — прежний путь OpenRouter');
    }

    public function test_empty_local_answer_is_null_not_garbage(): void
    {
        Http::fake([
            '127.0.0.1:11434/*' => Http::response([
                'choices' => [['message' => ['content' => '   ']]],
            ]),
        ]);

        $this->assertNull(app(CuratorAi::class)->reply($this->user, 'вопрос'));
    }
}
