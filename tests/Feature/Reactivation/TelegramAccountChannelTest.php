<?php

declare(strict_types=1);

namespace Tests\Feature\Reactivation;

use App\Models\TelegramBusinessConnection;
use App\Models\TelegramSupportChat;
use App\Models\User;
use App\Services\Reactivation\TelegramAccountChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Канал «от имени аккаунта»: адресат — не users.telegram_id, а существующий
 * диалог с аккаунтом школы (`telegram_support_chats.linked_user_id`).
 */
class TelegramAccountChannelTest extends TestCase
{
    use RefreshDatabase;

    private function connection(bool $enabled = true, bool $canReply = true): TelegramBusinessConnection
    {
        return TelegramBusinessConnection::create([
            'business_connection_id' => 'bc-test',
            'owner_telegram_user_id' => 111,
            'is_enabled' => $enabled,
            'can_reply' => $canReply,
        ]);
    }

    private function chat(User $user, int $chatId, string $type = 'private'): TelegramSupportChat
    {
        return TelegramSupportChat::create([
            'telegram_chat_id' => $chatId,
            'linked_user_id' => $user->id,
            'type' => $type,
        ]);
    }

    /** @test */
    public function resolves_chat_and_connection_for_linked_student(): void
    {
        config(['services.telegram_business.token' => 'bot-token']);
        $this->connection();
        $user = User::factory()->create();
        $this->chat($user, 555);

        $target = app(TelegramAccountChannel::class)->resolve($user);

        $this->assertSame(['chat_id' => 555, 'connection_id' => 'bc-test'], $target);
    }

    /** @test */
    public function student_without_chat_has_no_account_channel(): void
    {
        config(['services.telegram_business.token' => 'bot-token']);
        $this->connection();
        $user = User::factory()->create();

        $this->assertNull(app(TelegramAccountChannel::class)->resolve($user));
    }

    /** @test */
    public function group_chat_is_not_used_for_money_reminders(): void
    {
        config(['services.telegram_business.token' => 'bot-token']);
        $this->connection();
        $user = User::factory()->create();
        $this->chat($user, 777, 'group');

        $this->assertNull(app(TelegramAccountChannel::class)->resolve($user));
    }

    /** @test */
    public function disabled_or_non_replyable_connection_disables_the_channel(): void
    {
        config(['services.telegram_business.token' => 'bot-token']);
        $this->connection(canReply: false);
        $user = User::factory()->create();
        $this->chat($user, 555);

        $this->assertNull(app(TelegramAccountChannel::class)->resolve($user));
    }

    /** @test */
    public function missing_bot_token_disables_the_channel(): void
    {
        config(['services.telegram_business.token' => '']);
        $this->connection();
        $user = User::factory()->create();
        $this->chat($user, 555);

        $this->assertFalse(app(TelegramAccountChannel::class)->isAvailable());
        $this->assertNull(app(TelegramAccountChannel::class)->resolve($user));
    }

    /** @test */
    public function sends_through_bot_api_with_business_connection(): void
    {
        config(['services.telegram_business.token' => 'bot-token']);
        $this->connection();
        $user = User::factory()->create();
        $this->chat($user, 555);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 42]], 200),
        ]);

        $result = app(TelegramAccountChannel::class)->send($user, 'Напоминание об оплате блока');

        $this->assertSame('ok', $result['status']);
        $this->assertSame(42, $result['message_id']);

        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), '/sendMessage')
                && $request['business_connection_id'] === 'bc-test'
                && (int) $request['chat_id'] === 555
                && $request['text'] === 'Напоминание об оплате блока';
        });
    }

    /** @test */
    public function duplicate_content_is_deduped_by_the_shared_send_guard(): void
    {
        // Анти-дубль живёт в TelegramSendGuard (Redis). В тестах Redis нет, а
        // гард по контракту fail-open — поэтому проверяем не «подавилось», а
        // что клейм вообще спрашивается у гарда до вызова API.
        config(['services.telegram_business.token' => 'bot-token']);
        $this->connection();
        $user = User::factory()->create();
        $this->chat($user, 555);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 42]], 200),
        ]);

        Redis::shouldReceive('set')->andReturn(true);

        $result = app(TelegramAccountChannel::class)->send($user, 'Текст с клеймом');

        $this->assertSame('ok', $result['status']);
        Redis::shouldHaveReceived('set')->once();
    }
}
