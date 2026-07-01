<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\ChatMessage;
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
        config(['services.openrouter.api_key' => 'test-key']);
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => $content]]],
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
