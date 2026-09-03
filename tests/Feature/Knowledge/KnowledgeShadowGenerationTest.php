<?php

declare(strict_types=1);

namespace Tests\Feature\Knowledge;

use App\Jobs\OllamaShadowReplyJob;
use App\Models\ChatMessage;
use App\Models\SupportAiReplyEvent;
use App\Models\User;
use App\Services\Bot\CuratorAi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * H3234 (issue #1633 этап 5): теневая генерация. OpenRouter отвечает
 * студенту; локальный qwen3:14b пишет ответ в SupportAiReplyEvent
 * (ollama_shadow) РЯДОМ с онлайн-логом — неделя живого сравнения до флипа
 * этапа 6. Узел умер → событие status=error, студенту ничего не уходит.
 * Реюз dense-инфраструктуры H4001: тот же config/knowledge.php
 * (base_url + generation_model), второй конфиг не заводится.
 */
class KnowledgeShadowGenerationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'Тень',
            'email' => 'shadow'.uniqid().'@example.test',
            'password' => 'password',
        ]);

        config([
            'features.bot_local_generation' => false,
            'features.bot_ollama_shadow' => true,
            'services.openrouter.api_key' => 'test-key',
            'services.openrouter.base_url' => 'https://openrouter.test/api/v1',
            'services.openrouter.model' => 'deepseek/deepseek-v4-flash',
            'knowledge.driver' => 'ollama',
            'knowledge.base_url' => 'http://127.0.0.1:11434',
            'knowledge.generation_model' => 'qwen3:14b',
        ]);

        Cache::flush();
        Queue::fake();
    }

    /**
     * ОДИН fake на сценарий: Http::fake() НЕ перезаписывает прежние стабы, а
     * добавляет их в конец коллекции — второй вызов fake() не отменяет первый.
     */
    private function fakeHttp(string $localBody, int $localStatus = 200): void
    {
        Http::fake([
            'openrouter.test/*' => Http::response([
                'choices' => [['message' => ['content' => 'ОТВЕТ-ONLINE']]],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20],
                'model' => 'deepseek/deepseek-v4-flash',
            ]),
            '127.0.0.1:11434/*' => Http::response([
                'choices' => [['message' => ['content' => $localBody]]],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 30],
                'model' => 'qwen3:14b',
            ], $localStatus),
        ]);
    }

    private function prompt(): array
    {
        return app(CuratorAi::class)->messagesFor($this->user, 'как оплатить курс?');
    }

    public function test_successful_online_reply_dispatches_the_shadow_job(): void
    {
        $this->fakeHttp('ОТВЕТ-ЛОКАЛЬНЫЙ');

        $answer = app(CuratorAi::class)->reply($this->user, 'как оплатить курс?');

        $this->assertSame('ОТВЕТ-ONLINE', $answer, 'в тени студенту отвечает OpenRouter, а не локальная модель');
        Queue::assertPushed(OllamaShadowReplyJob::class);
    }

    public function test_flag_off_does_not_shadow(): void
    {
        config(['features.bot_ollama_shadow' => false]);
        $this->fakeHttp('ОТВЕТ-ЛОКАЛЬНЫЙ');

        app(CuratorAi::class)->reply($this->user, 'как оплатить курс?');

        Queue::assertNothingPushed();
    }

    public function test_failed_online_reply_has_nothing_to_shadow(): void
    {
        Http::fake([
            'openrouter.test/*' => Http::response('boom', 500),
            '127.0.0.1:11434/*' => Http::response(['choices' => [['message' => ['content' => 'x']]]]),
        ]);

        $answer = app(CuratorAi::class)->reply($this->user, 'как оплатить курс?');

        $this->assertNull($answer);
        Queue::assertNothingPushed();
    }

    public function test_shadow_job_logs_ok_event_next_to_the_online_log(): void
    {
        $this->fakeHttp('ОТВЕТ-ЛОКАЛЬНЫЙ');

        // Рядом пишем «онлайн» событие — как это делает живой путь LLM-логов.
        SupportAiReplyEvent::create([
            'telegram_support_message_id' => null,
            'event_type' => 'suggested',
            'meta' => ['scope' => 'online', 'model' => 'deepseek/deepseek-v4-flash'],
        ]);

        (new OllamaShadowReplyJob($this->user->id, $this->prompt(), 'как оплатить курс?'))
            ->handle(app(CuratorAi::class));

        $shadow = SupportAiReplyEvent::query()->where('event_type', SupportAiReplyEvent::EVENT_OLLAMA_SHADOW)->firstOrFail();
        $this->assertSame('ok', $shadow->meta['status']);
        $this->assertSame('ОТВЕТ-ЛОКАЛЬНЫЙ', $shadow->meta['preview']);
        $this->assertSame($this->user->id, $shadow->meta['user_id']);
        $this->assertSame('qwen3:14b', $shadow->meta['model']);
        $this->assertSame(30, $shadow->meta['usage']['completion_tokens']);
        $this->assertSame('как оплатить курс?', $shadow->meta['question_preview']);

        $this->assertSame(2, SupportAiReplyEvent::query()->count(), 'теневой лог идёт РЯДОМ с онлайн-логом, не вместо');
    }

    public function test_shadow_job_logs_error_event_when_the_tunnel_is_down(): void
    {
        $this->fakeHttp('irrelevant', 500);

        (new OllamaShadowReplyJob($this->user->id, $this->prompt(), 'как оплатить курс?'))
            ->handle(app(CuratorAi::class));

        $shadow = SupportAiReplyEvent::query()->where('event_type', SupportAiReplyEvent::EVENT_OLLAMA_SHADOW)->firstOrFail();
        $this->assertSame('error', $shadow->meta['status']);
        $this->assertNull($shadow->meta['preview']);
    }

    public function test_shadow_prompt_equals_the_online_prompt(): void
    {
        // Тень обязана видеть ТОТ ЖЕ промпт, что ушёл в OpenRouter: системный
        // промпт + история диалога, где входящее студента УЖЕ сохранено
        // контроллером (в живом потоке оно попадает в историю последним).
        ChatMessage::query()->create([
            'user_id' => $this->user->id,
            'role' => 'user',
            'text' => 'привет',
            'is_read' => true,
        ]);
        ChatMessage::query()->create([
            'user_id' => $this->user->id,
            'role' => 'user',
            'text' => 'как оплатить курс?',
            'is_read' => true,
        ]);

        $messages = $this->prompt();

        $this->assertSame('system', $messages[0]['role']);
        $this->assertSame('user', $messages[1]['role']);
        $this->assertSame('привет', $messages[1]['content']);
        $this->assertSame('как оплатить курс?', $messages[2]['content'], 'входящее — последнее в истории');
        $this->assertSame($messages, app(CuratorAi::class)->messagesFor($this->user, 'как оплатить курс?'));
    }
}
