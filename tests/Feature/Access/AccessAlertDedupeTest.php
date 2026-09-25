<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Models\AccessAttempt;
use App\Services\Access\AccessAttemptLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Дедупликация алертов «студент не может войти» (10 минут на email|ip) не
 * должна съедать сам сигнал: если Telegram недоступен и доставка не удалась,
 * ключ снимается, и следующий алерт по тому же email|ip уходит, как только
 * сеть ожила. Иначе ровно в аварию сигнал терялся на весь TTL.
 */
class AccessAlertDedupeTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'stuck@example.com';

    private const IP = '203.0.113.9';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.bot_token' => '123456:SECRET',
            'services.telegram.admin_id' => '555',
        ]);
    }

    public function test_delivery_failure_releases_the_dedupe_key_so_the_next_alert_is_sent(): void
    {
        // Один стаб с состоянием: Http::fake() ДОПИСЫВАЕТ стабы, и первый
        // зарегистрированный матчится раньше — второй вызов fake() не перекрыл бы
        // бросающий. Первая попытка падает, вторая «оживает».
        $failNext = true;
        Http::fake([
            'api.telegram.org/*' => function () use (&$failNext) {
                if ($failNext) {
                    $failNext = false;

                    throw new ConnectionException('cURL error 28: Operation timed out after 10000 milliseconds');
                }

                return Http::response(['ok' => true], 200);
            },
        ]);

        $logger = app(AccessAttemptLogger::class);

        // Telegram лежит: алерт ушёл в никуда, ключ дедупликации снят.
        $logger->record(AccessAttempt::KIND_LOCKOUT, self::EMAIL, null, self::IP);

        // Сеть ожила — по тому же email|ip алерт обязан уйти, а не молчать 600 с.
        $logger->record(AccessAttempt::KIND_LOCKOUT, self::EMAIL, null, self::IP);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), self::EMAIL));
    }

    public function test_successful_delivery_keeps_the_dedupe_key(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);
        $logger = app(AccessAttemptLogger::class);

        $logger->record(AccessAttempt::KIND_LOCKOUT, self::EMAIL, null, self::IP);
        $logger->record(AccessAttempt::KIND_LOCKOUT, self::EMAIL, null, self::IP);

        Http::assertSentCount(1);   // второй раз молчим — дедупликация на месте
    }

    public function test_missing_recipients_is_not_a_delivery_failure(): void
    {
        Http::fake();   // наружу ходить нечем и незачем

        config(['services.telegram.admin_id' => '']);
        $logger = app(AccessAttemptLogger::class);
        $logger->record(AccessAttempt::KIND_LOCKOUT, self::EMAIL, null, self::IP);

        // Получатели появились: ключ НЕ снят (пустой список — не сбой сети),
        // поэтому повторный алерт в окне дедупликации не отправляется.
        config(['services.telegram.admin_id' => '555']);
        $logger->record(AccessAttempt::KIND_LOCKOUT, self::EMAIL, null, self::IP);

        Http::assertNothingSent();
    }
}
