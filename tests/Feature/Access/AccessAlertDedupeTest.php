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
 * Дедупликация алертов «студент не может войти» (10 минут на email|ip) и
 * авария Telegram — два противоположных риска:
 *  - ключ на все 600 с теряет сигнал ровно в аварию (его и надо было починить);
 *  - снятие ключа на каждом сбое выключает ограничитель частоты и делает аварию
 *    усилителем: для KIND_FAILED_LOGIN порог «3 в окне 15 мин» истинен на
 *    каждом следующем провале, то есть каждая попытка входа = новая сетевая
 *    попытка по 2–5 с (вектор исчерпания пула FPM).
 * Поэтому неудачная доставка переставляет ключ с коротким TTL (60 с):
 * сигнал возвращается через минуту, но не на каждом событии.
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

    public function test_failed_delivery_retries_only_after_the_short_ttl(): void
    {
        // Один стаб с состоянием: Http::fake() ДОПИСЫВАЕТ стабы, и первый
        // зарегистрированный матчится раньше — второй вызов fake() не перекрыл бы
        // бросающий. Первая попытка падает, следующая после TTL — успешна.
        $failNext = true;
        $attempts = 0;
        Http::fake([
            'api.telegram.org/*' => function () use (&$failNext, &$attempts) {
                $attempts++;

                if ($failNext) {
                    $failNext = false;

                    throw new ConnectionException('cURL error 28: Operation timed out after 10000 milliseconds');
                }

                return Http::response(['ok' => true], 200);
            },
        ]);

        $logger = app(AccessAttemptLogger::class);

        // Telegram лежит: алерт ушёл в никуда.
        $logger->record(AccessAttempt::KIND_LOCKOUT, self::EMAIL, null, self::IP);
        $this->assertSame(1, $attempts);

        // Сразу следующее подходящее событие НЕ повторяет попытку: ключ
        // переставлен с коротким TTL, ограничитель частоты на месте.
        $logger->record(AccessAttempt::KIND_LOCKOUT, self::EMAIL, null, self::IP);
        $this->assertSame(1, $attempts, 'повтор возможен не раньше ALERT_RETRY_TTL, а не на каждом событии');

        // Прошла минута — сигнал не потерян: алерт уходит.
        $this->travel(61)->seconds();
        $logger->record(AccessAttempt::KIND_LOCKOUT, self::EMAIL, null, self::IP);

        $this->assertSame(2, $attempts, 'после короткого TTL попытка должна быть повторена');
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/sendMessage')
            && str_contains((string) ($request['text'] ?? ''), self::EMAIL));
    }

    public function test_a_burst_of_events_in_an_outage_stays_one_attempt(): void
    {
        $attempts = 0;
        Http::fake([
            'api.telegram.org/*' => function () use (&$attempts) {
                $attempts++;

                throw new ConnectionException('cURL error 28: Operation timed out after 10000 milliseconds');
            },
        ]);

        $logger = app(AccessAttemptLogger::class);
        foreach (range(1, 6) as $ignored) {
            $logger->record(AccessAttempt::KIND_LOCKOUT, self::EMAIL, null, self::IP);
        }

        $this->assertSame(
            1,
            $attempts,
            'шесть событий в аварию = одна сетевая попытка, иначе 2–5 с воркера на каждое событие'
        );
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

    public function test_empty_token_with_recipients_is_not_a_delivery_failure(): void
    {
        Http::fake();   // наружу ходить нечем

        config(['services.telegram.bot_token' => '']);

        $logger = app(AccessAttemptLogger::class);
        $logger->record(AccessAttempt::KIND_LOCKOUT, self::EMAIL, null, self::IP);

        // Токен появился: ключ НЕ снят («отправлять нечем» — не сбой сети),
        // поэтому повторный алерт в окне дедупликации не отправляется.
        config(['services.telegram.bot_token' => '123456:SECRET']);
        $logger->record(AccessAttempt::KIND_LOCKOUT, self::EMAIL, null, self::IP);

        Http::assertNothingSent();
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
