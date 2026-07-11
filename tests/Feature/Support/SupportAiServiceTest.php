<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\ChatMessage;
use App\Models\SupportAiReplyEvent;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Services\Support\SupportAiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SupportAiServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fakeOpenRouter(string $content): void
    {
        config([
            'services.openrouter.api_key' => 'test-key',
            'services.openrouter.model' => 'deepseek/deepseek-chat',
        ]);
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'model' => 'deepseek/deepseek-chat',
                'choices' => [['message' => ['content' => $content]]],
                'usage' => ['prompt_tokens' => 200, 'completion_tokens' => 80],
            ], 200),
        ]);
    }

    private function studentWithMessage(): User
    {
        $user = User::factory()->create();
        ChatMessage::create([
            'user_id' => $user->id,
            'role' => 'user',
            'text' => 'Когда начинается курс?',
            'is_read' => false,
        ]);

        return $user;
    }

    private function studentWithTelegramMessage(): User
    {
        $user = User::factory()->create();
        $account = TelegramSupportAccount::create(['name' => 'support']);
        $chat = TelegramSupportChat::create([
            'telegram_chat_id' => 9700,
            'linked_user_id' => $user->id,
        ]);
        TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_chat_id' => 9700,
            'telegram_message_id' => 1,
            'direction' => 'incoming',
            'text' => 'Приватный вопрос из личных сообщений',
            'sent_at' => now(),
        ]);

        return $user;
    }

    public function test_telegram_dm_not_sent_to_llm_by_default(): void
    {
        config([
            'features.support_ai_assist' => true,
            'features.support_ai_include_telegram' => false,
        ]);
        Http::fake();
        $user = $this->studentWithTelegramMessage();

        // Контекст только из TG → веб-истории нет → ИИ не вызывается, ничего не уходит.
        $this->assertNull(app(SupportAiService::class)->suggestReply($user));

        Http::assertNothingSent();
        $this->assertDatabaseCount('support_ai_reply_events', 0);
    }

    public function test_telegram_dm_sent_to_llm_when_opted_in(): void
    {
        config([
            'features.support_ai_assist' => true,
            'features.support_ai_include_telegram' => true,
        ]);
        $this->fakeOpenRouter('Черновик ответа');
        $user = $this->studentWithTelegramMessage();

        $draft = app(SupportAiService::class)->suggestReply($user);

        $this->assertSame('Черновик ответа', $draft);
        Http::assertSent(fn ($request) => str_contains(
            json_encode($request->data(), JSON_UNESCAPED_UNICODE),
            'Приватный вопрос из личных сообщений',
        ));
    }

    public function test_disabled_flag_returns_null_and_calls_no_api(): void
    {
        config(['features.support_ai_assist' => false]);
        Http::fake();
        $user = $this->studentWithMessage();

        $this->assertNull(app(SupportAiService::class)->suggestReply($user));

        Http::assertNothingSent();
        $this->assertDatabaseCount('support_ai_reply_events', 0);
    }

    public function test_suggest_reply_returns_draft_and_logs_event(): void
    {
        config(['features.support_ai_assist' => true]);
        $this->fakeOpenRouter('Курс начинается 1 сентября.');
        $user = $this->studentWithMessage();

        $draft = app(SupportAiService::class)->suggestReply($user);

        $this->assertSame('Курс начинается 1 сентября.', $draft);
        $this->assertDatabaseHas('support_ai_reply_events', [
            'event_type' => 'suggested',
            'telegram_support_message_id' => null,
        ]);

        $event = SupportAiReplyEvent::query()->latest('id')->first();
        $this->assertSame('deepseek/deepseek-chat', $event->meta['model']);
        $this->assertSame(['prompt_tokens' => 200, 'completion_tokens' => 80], $event->meta['usage']);
    }

    public function test_summarize_logs_summary_event(): void
    {
        config(['features.support_ai_assist' => true]);
        $this->fakeOpenRouter('• Спрашивает дату старта курса');
        $user = $this->studentWithMessage();

        $summary = app(SupportAiService::class)->summarize($user);

        $this->assertNotNull($summary);
        $this->assertDatabaseHas('support_ai_reply_events', [
            'event_type' => 'summary',
        ]);
    }
}
