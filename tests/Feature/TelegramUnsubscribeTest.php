<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\TelegramWebhookController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Отписка от рекламной рассылки в мессенджеры через бота (152-ФЗ, H1430).
 * Тестируем handle() напрямую, минуя middleware verify.tg.bot; исходящий
 * sendMessage к Telegram API фейкается.
 */
class TelegramUnsubscribeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
    }

    private function handle(int $chatId, string $text): void
    {
        $request = Request::create('/api/telegram/webhook', 'POST', [
            'message' => ['chat' => ['id' => $chatId], 'text' => $text, 'from' => ['username' => 'x']],
        ]);

        app(TelegramWebhookController::class)->handle($request);
    }

    public function test_stop_command_unsubscribes_linked_user(): void
    {
        $user = User::factory()->create([
            'telegram_id' => 555,
            'wants_messenger_announcements' => true,
        ]);

        $this->handle(555, '/stop');

        $this->assertFalse($user->fresh()->wants_messenger_announcements);
    }

    public function test_ru_synonym_unsubscribes(): void
    {
        $user = User::factory()->create([
            'telegram_id' => 556,
            'wants_messenger_announcements' => true,
        ]);

        $this->handle(556, 'отписаться');

        $this->assertFalse($user->fresh()->wants_messenger_announcements);
    }

    public function test_ordinary_message_does_not_touch_consent(): void
    {
        // «мои группы» — self-service ветка (только БД, без внешних вызовов);
        // проверяем, что обычное сообщение НЕ трогает флаг согласия.
        $user = User::factory()->create([
            'telegram_id' => 557,
            'wants_messenger_announcements' => true,
        ]);

        $this->handle(557, 'мои группы');

        $this->assertTrue($user->fresh()->wants_messenger_announcements);
    }
}
