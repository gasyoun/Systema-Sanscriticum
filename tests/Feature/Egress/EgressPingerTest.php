<?php

declare(strict_types=1);

namespace Tests\Feature\Egress;

use App\Support\Egress\EgressPinger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Общий транспорт пульсов (H7565): прямая нога → нога через прокси.
 *
 * Ломается это молча, поэтому пиним ровно те свойства, что дают обводку
 * H7556 и не ломают прежнее поведение:
 *
 *   1) пустой egress.ping_proxy = прежний транспорт байт-в-байт (одна
 *      попытка, никакой прокси-ноги);
 *   2) HTTP-ответ любого кода = путь жив, прокси не подключаем (4xx/5xx —
 *      сторона приёмника; переприём мог бы задвоить пинг);
 *   3) transient-перепроба на прокси-ноге ровно одна (sub-5s DNS-таймауты
 *      userspace-tailscaled — дефект, словленный системд-ногами 31-10).
 */
class EgressPingerTest extends TestCase
{
    private const TARGET = 'https://hc-ping.example/heartbeat-token';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('egress.ping_proxy', '');
    }

    public function test_direct_success_does_not_touch_the_proxy(): void
    {
        Http::fake([self::TARGET => Http::response('OK', 200)]);

        $delivery = app(EgressPinger::class)->send(self::TARGET, 'ok', 5);

        $this->assertTrue($delivery->delivered);
        $this->assertSame('direct', $delivery->via);
        $this->assertSame(200, $delivery->status);
        Http::assertSentCount(1);
    }

    public function test_direct_http_error_does_not_retry_via_proxy(): void
    {
        // Приёмник ответил — путь жив; прокси здесь не лечит и не подключается.
        Http::fake([self::TARGET => Http::response('nope', 500)]);

        $delivery = app(EgressPinger::class)->send(self::TARGET, 'ok', 5);

        $this->assertFalse($delivery->delivered);
        $this->assertSame('direct', $delivery->via);
        $this->assertSame(500, $delivery->status);
        Http::assertSentCount(1);
    }

    public function test_transport_failure_without_proxy_stays_a_single_direct_attempt(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Connection timed out'));

        $delivery = app(EgressPinger::class)->send(self::TARGET, 'ok', 5);

        $this->assertFalse($delivery->delivered);
        $this->assertSame('none', $delivery->via);
        $this->assertNull($delivery->status);
        $this->assertStringContainsString('cURL error 28', $delivery->detail);
        Http::assertSentCount(1);
    }

    public function test_transport_failure_falls_over_to_the_proxy_leg(): void
    {
        config()->set('egress.ping_proxy', 'socks5h://127.0.0.1:1055');

        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;

            // 1-й вызов — прямая нога (транспорт мёртв), 2-й — прокси-нога.
            return $attempts === 1
                ? throw new ConnectionException('cURL error 28: Connection timed out')
                : Http::response('OK', 200);
        });

        $delivery = app(EgressPinger::class)->send(self::TARGET, 'ok', 5);

        $this->assertTrue($delivery->delivered);
        $this->assertSame('proxy', $delivery->via);
        $this->assertSame(200, $delivery->status);
        $this->assertSame(2, $attempts);
    }

    public function test_proxy_leg_retries_once_on_a_transient_timeout(): void
    {
        config()->set('egress.ping_proxy', 'socks5h://127.0.0.1:1055');

        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;

            // прямая (мёртв) → прокси-попытка 1 (transient DNS) → прокси-попытка 2 (успех).
            return $attempts <= 2
                ? throw new ConnectionException('lookup hc-ping.example: i/o timeout')
                : Http::response('OK', 200);
        });

        $delivery = app(EgressPinger::class)->send(self::TARGET, 'ok', 5);

        $this->assertTrue($delivery->delivered);
        $this->assertSame('proxy', $delivery->via);
        $this->assertSame(3, $attempts);
    }

    public function test_dead_direct_and_dead_proxy_report_both_legs(): void
    {
        config()->set('egress.ping_proxy', 'socks5h://127.0.0.1:1055');

        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;

            return $attempts === 1
                ? throw new ConnectionException('cURL error 28: Connection timed out')
                : throw new ConnectionException('lookup hc-ping.example: i/o timeout');
        });

        $delivery = app(EgressPinger::class)->send(self::TARGET, 'ok', 5);

        $this->assertFalse($delivery->delivered);
        $this->assertSame('none', $delivery->via);
        $this->assertStringContainsString('прямая нога: cURL error 28', $delivery->detail);
        $this->assertStringContainsString('через прокси: lookup hc-ping.example', $delivery->detail);
        // прямая + две прокси-попытки, перепроба ровно одна.
        $this->assertSame(3, $attempts);
    }
}
