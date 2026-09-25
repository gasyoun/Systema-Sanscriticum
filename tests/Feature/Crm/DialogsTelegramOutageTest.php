<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Filament\Resources\UserResource\Pages\Dialogs;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Второй кураторский экшен того же класса, что Helpdesk: запись в ChatMessage
 * уже сделана, а отправка в Telegram идёт следом. Недоступный Telegram не
 * имеет права превращать ответ куратора в 500 (прод 25-09-2026).
 *
 * Экшен вызывается напрямую: страница ресурса Filament не поднимается через
 * Livewire-тест (снапшот компонента не резолвится вне запроса панели), а
 * проверяем мы ровно метод sendMessageToStudent.
 */
class DialogsTelegramOutageTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '123456:SECRET';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.telegram.bot_token', self::TOKEN);
    }

    public function test_curator_message_is_saved_when_telegram_is_down(): void
    {
        Http::fake([
            'api.telegram.org/*' => fn () => throw new ConnectionException(
                'cURL error 28: Operation timed out after 10000 milliseconds for '
                .'https://api.telegram.org/bot'.self::TOKEN.'/sendMessage'
            ),
        ]);

        $curator = User::factory()->create(['is_admin' => true, 'curator_display_name' => 'Маша']);
        $student = User::factory()->create(['telegram_id' => 555000444]);

        $this->actingAs($curator);

        $page = new Dialogs;
        $page->activeUserId = $student->id;
        $page->newMessage = 'Ответ из чата куратора';
        $page->sendMessageToStudent();

        $this->assertDatabaseHas('chat_messages', [
            'user_id' => $student->id,
            'role' => 'curator',
            'text' => 'Ответ из чата куратора',
        ]);
    }
}
