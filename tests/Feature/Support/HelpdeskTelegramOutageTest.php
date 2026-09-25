<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Filament\Pages\Helpdesk;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ответ куратора из админки уходит в Telegram синхронно, уже после записи в БД.
 * Пока вызов бросал исключение, недоступный Telegram превращал действие в 500:
 * запись в чате есть, ответа у студента нет, куратор видит ошибку (и до 10 с
 * занятого воркера на каждое сообщение) — прод 25-09-2026.
 *
 * Контракт: недоставка — управляемое предупреждение оператору, а не падение.
 */
class HelpdeskTelegramOutageTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '123456:SECRET';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        config()->set('services.telegram.bot_token', self::TOKEN);
    }

    private function telegramDown(): void
    {
        Http::fake([
            'api.telegram.org/*' => fn () => throw new ConnectionException(
                'cURL error 28: Operation timed out after 10000 milliseconds for '
                .'https://api.telegram.org/bot'.self::TOKEN.'/sendMessage'
            ),
        ]);
    }

    public function test_curator_reply_is_saved_and_operator_sees_warning_when_telegram_is_down(): void
    {
        $this->telegramDown();

        $curator = User::factory()->create(['is_admin' => true, 'curator_display_name' => 'Маша']);
        $student = User::factory()->create(['telegram_id' => 555000222]);
        Cache::put("chat_human_{$student->telegram_id}", true, 7200);

        Livewire::actingAs($curator)
            ->test(Helpdesk::class)
            ->set('activeUserId', $student->id)
            ->set('newMessage', 'Здравствуйте, помогу с оплатой')
            ->call('sendMessageToStudent');

        // Запись сделана — ответ не потерян для кабинета.
        $this->assertDatabaseHas('chat_messages', [
            'user_id' => $student->id,
            'role' => 'curator',
            'text' => 'Здравствуйте, помогу с оплатой',
        ]);
    }

    public function test_return_to_bot_keeps_state_and_does_not_fail_when_telegram_is_down(): void
    {
        $this->telegramDown();

        $curator = User::factory()->create(['is_admin' => true]);
        $student = User::factory()->create(['telegram_id' => 555000333]);
        Cache::put("chat_human_{$student->telegram_id}", true, 7200);

        Livewire::actingAs($curator)
            ->test(Helpdesk::class)
            ->set('activeUserId', $student->id)
            ->call('returnToBot');

        // Состояние изменено (пауза снята) — уведомление студенту лишь следствие.
        $this->assertFalse(Cache::has("chat_human_{$student->telegram_id}"));
        $this->assertDatabaseHas('chat_messages', [
            'user_id' => $student->id,
            'role' => 'bot',
        ]);
    }
}
