<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Services\Support\SupportDmAutoReply;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * H3393: подсказки «сложный вопрос» идут получателям аккаунта
 * (hint_recipients), а не только админам; пусто — прежнее поведение.
 */
class HintRoutingTest extends TestCase
{
    use RefreshDatabase;

    private function incoming(TelegramSupportAccount $account, int $chatId): TelegramSupportMessage
    {
        $user = User::factory()->create();
        $chat = TelegramSupportChat::firstOrCreate(
            ['telegram_chat_id' => $chatId],
            ['linked_user_id' => $user->id, 'last_message_at' => now()],
        );

        return TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_chat_id' => $chatId,
            'telegram_message_id' => random_int(1, 1_000_000),
            'direction' => 'incoming',
            'text' => 'подскажите что-то совершенно непонятное',
            'sent_at' => now(),
        ]);
    }

    public function test_hint_goes_to_account_recipients_instead_of_admins(): void
    {
        config([
            'features.support_dm_auto_reply' => true,
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.admin_id' => '111',
        ]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $account = TelegramSupportAccount::create([
            'name' => 'rusamskrtam',
            'auto_reply_enabled' => true,
            'hint_recipients' => ['777'],
        ]);
        $incoming = $this->incoming($account, 9201);

        app(SupportDmAutoReply::class)->handle($incoming, null, 'private');

        Http::assertSent(fn ($r) => str_contains((string) ($r['chat_id'] ?? ''), '777'));
        Http::assertNotSent(fn ($r) => str_contains((string) ($r['chat_id'] ?? ''), '111'));
    }

    public function test_empty_recipients_falls_back_to_admins(): void
    {
        config([
            'features.support_dm_auto_reply' => true,
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.admin_id' => '111',
        ]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $account = TelegramSupportAccount::create([
            'name' => 'rusamskrtam',
            'auto_reply_enabled' => true,
        ]);
        $incoming = $this->incoming($account, 9202);

        app(SupportDmAutoReply::class)->handle($incoming, null, 'private');

        Http::assertSent(fn ($r) => str_contains((string) ($r['chat_id'] ?? ''), '111'));
    }
}
