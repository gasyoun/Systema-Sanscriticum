<?php

declare(strict_types=1);

namespace Tests\Feature\Bot;

use App\Models\ChatMessage;
use App\Models\Course;
use App\Models\Tariff;
use App\Models\User;
use App\Services\Bot\BotKnowledgeBase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CuratorWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config()->set('services.openrouter.api_key', 'test-key');
        config()->set('services.openrouter.model', 'deepseek/deepseek-chat');

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => 'Намасте! Курс стоит 16500 ₽.']]],
            ], 200),
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
            'api.vk.com/*' => Http::response(['response' => 1], 200),
        ]);
    }

    public function test_system_prompt_combines_persona_faq_and_catalog(): void
    {
        Course::factory()->create(['title' => 'Тестовый Курс Альфа']);

        $prompt = app(BotKnowledgeBase::class)->systemPrompt('сколько стоит');

        $this->assertStringContainsString('ИИ-куратор Академии Санскрита', $prompt); // persona
        $this->assertStringContainsString('База знаний (FAQ', $prompt);              // FAQ
        $this->assertStringContainsString('Каталог курсов', $prompt);               // catalog
        $this->assertStringContainsString('Тестовый Курс Альфа', $prompt);          // live data
    }

    public function test_telegram_webhook_answers_via_openrouter_and_saves_reply(): void
    {
        $user = User::factory()->create(['telegram_id' => 999111, 'name' => 'Студент']);

        $this->postJson('/api/telegram/webhook', [
            'message' => ['chat' => ['id' => 999111], 'text' => 'Сколько стоит курс?'],
        ])->assertOk();

        // Вопрос и ответ сохранены.
        $this->assertDatabaseHas('chat_messages', ['user_id' => $user->id, 'role' => 'user', 'text' => 'Сколько стоит курс?']);
        $this->assertDatabaseHas('chat_messages', ['user_id' => $user->id, 'role' => 'bot', 'text' => 'Намасте! Курс стоит 16500 ₽.']);

        // Запрос ушёл в OpenRouter с нужной моделью и системным промптом.
        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'openrouter.ai')) {
                return false;
            }
            $this->assertSame('Bearer test-key', $request->header('Authorization')[0]);
            $body = $request->data();

            return ($body['model'] ?? null) === 'deepseek/deepseek-chat'
                && str_contains($body['messages'][0]['content'] ?? '', 'ИИ-куратор Академии Санскрита');
        });
    }

    public function test_vk_webhook_answers_via_openrouter_and_saves_reply(): void
    {
        $user = User::factory()->create(['vk_id' => 555222, 'name' => 'Студент ВК']);

        $this->postJson('/api/vk-webhook', [
            'type' => 'message_new',
            'object' => ['message' => ['from_id' => 555222, 'text' => 'Сколько стоит курс?']],
        ])->assertOk();

        $this->assertDatabaseHas('chat_messages', ['user_id' => $user->id, 'role' => 'user']);
        $this->assertDatabaseHas('chat_messages', ['user_id' => $user->id, 'role' => 'bot', 'text' => 'Намасте! Курс стоит 16500 ₽.']);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'openrouter.ai'));
    }

    public function test_curator_trigger_word_escalates_without_calling_ai(): void
    {
        $user = User::factory()->create(['telegram_id' => 999333, 'name' => 'Студент']);

        $this->postJson('/api/telegram/webhook', [
            'message' => ['chat' => ['id' => 999333], 'text' => 'позови куратора'],
        ])->assertOk();

        // Вопрос сохранён, но ответа бота нет — ушли к человеку.
        $this->assertDatabaseHas('chat_messages', ['user_id' => $user->id, 'role' => 'user', 'text' => 'позови куратора']);
        $this->assertDatabaseMissing('chat_messages', ['user_id' => $user->id, 'role' => 'bot']);

        // В OpenRouter не ходили.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'openrouter.ai'));
    }

    public function test_reply_is_null_when_api_key_missing(): void
    {
        config()->set('services.openrouter.api_key', '');
        $user = User::factory()->create(['telegram_id' => 999444, 'name' => 'Студент']);

        $this->postJson('/api/telegram/webhook', [
            'message' => ['chat' => ['id' => 999444], 'text' => 'Привет'],
        ])->assertOk();

        // Без ключа ответа ИИ нет (мягкая ошибка), бот-сообщение не сохраняется.
        $this->assertDatabaseMissing('chat_messages', ['user_id' => $user->id, 'role' => 'bot']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'openrouter.ai'));
    }
}
