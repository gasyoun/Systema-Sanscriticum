<?php

declare(strict_types=1);

namespace Tests\Feature\Bot;

use App\Services\Bot\CuratorAi;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CuratorAiUsageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.openrouter.api_key', 'test-key');
        config()->set('services.openrouter.model', 'deepseek/deepseek-chat');
    }

    public function test_chat_with_usage_captures_tokens_and_model(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'model' => 'deepseek/deepseek-chat',
                'choices' => [['message' => ['content' => 'Namaste']]],
                'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 45],
            ], 200),
        ]);

        $result = app(CuratorAi::class)->chatWithUsage([
            ['role' => 'user', 'content' => 'hi'],
        ]);

        $this->assertSame('Namaste', $result['content']);
        $this->assertSame('deepseek/deepseek-chat', $result['model']);
        $this->assertSame(['prompt_tokens' => 120, 'completion_tokens' => 45], $result['usage']);
    }

    public function test_chat_still_returns_bare_content_string(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => 'Namaste']]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ], 200),
        ]);

        $answer = app(CuratorAi::class)->chat([['role' => 'user', 'content' => 'hi']]);

        $this->assertSame('Namaste', $answer);
    }

    public function test_usage_is_null_when_openrouter_omits_it(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => 'Namaste']]],
            ], 200),
        ]);

        $result = app(CuratorAi::class)->chatWithUsage([['role' => 'user', 'content' => 'hi']]);

        $this->assertSame('Namaste', $result['content']);
        $this->assertNull($result['usage']);
    }

    public function test_error_response_returns_null_content_and_usage(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response(['error' => 'boom'], 500),
        ]);

        $result = app(CuratorAi::class)->chatWithUsage([['role' => 'user', 'content' => 'hi']]);

        $this->assertNull($result['content']);
        $this->assertNull($result['usage']);
    }
}
