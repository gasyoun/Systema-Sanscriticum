<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Telegram\MadelineClientFactory;
use App\Services\Telegram\MadelineDaemonSupervisor;
use RuntimeException;
use Tests\Support\FakeDaemonProcessProbe;
use Tests\TestCase;

/**
 * H3121: демон MadelineProto обязан жить в СВОЕЙ cgroup и под потолком.
 *
 * Каждый тест воспроизводит одно состояние прода 19-08-2026 и требует от
 * супервизора именно того действия, которого в тот день не сделал никто.
 */
class MadelineDaemonSupervisorTest extends TestCase
{
    private function supervisor(
        FakeDaemonProcessProbe $probe,
        MadelineClientFactory $factory,
        int $maxRssMb = 700,
        int $maxFds = 2000,
        int $maxAgeHours = 12,
    ): MadelineDaemonSupervisor {
        return new MadelineDaemonSupervisor(
            $probe,
            $factory,
            maxRssKb: $maxRssMb * 1024,
            maxFds: $maxFds,
            maxAgeSeconds: $maxAgeHours * 3600,
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Путь сессии обязан быть непустым: по нему супервизор ищет процессы.
        config()->set('services.telegram_support.session', '/var/www/html/storage/app/telegram-support/session.madeline');
    }

    public function test_a_daemon_in_the_cron_cgroup_is_killed(): void
    {
        // Сердце инцидента: демон здоров по всем метрикам, но живёт за счёт
        // бюджета cron.service — и тормозит планировщик, сторожей и деплой.
        $probe = new FakeDaemonProcessProbe;
        $probe->add(2557501, FakeDaemonProcessProbe::CRON_CGROUP);
        $factory = new SpawningFactoryStub($probe);

        $result = $this->supervisor($probe, $factory)->tick();

        $this->assertSame([2557501], array_column($result['killed'], 'pid'));
        $this->assertSame(MadelineDaemonSupervisor::REASON_FOREIGN_CGROUP, $result['killed'][0]['reason']);
        $this->assertStringContainsString('cron.service', $result['killed'][0]['detail']);
        // И тут же поднят заново — уже из нашей группы.
        $this->assertTrue($result['spawned']);
    }

    public function test_a_healthy_daemon_in_our_own_cgroup_is_left_alone(): void
    {
        $probe = new FakeDaemonProcessProbe;
        $probe->add(2695029);
        $factory = new SpawningFactoryStub($probe);

        $result = $this->supervisor($probe, $factory)->tick();

        $this->assertSame([], $result['killed']);
        $this->assertSame([2695029], $result['healthy']);
        $this->assertFalse($result['spawned'], 'живого демона второй раз не поднимают');
        $this->assertSame(0, $factory->opened);
    }

    public function test_a_bloated_daemon_is_reaped_by_rss(): void
    {
        // 2.29 ГиБ — ровно то, до чего он дорос за 33 часа.
        $probe = new FakeDaemonProcessProbe;
        $probe->add(2557501, rssKb: 2_400_000);
        $factory = new SpawningFactoryStub($probe);

        $result = $this->supervisor($probe, $factory)->tick();

        $this->assertSame(MadelineDaemonSupervisor::REASON_RSS, $result['killed'][0]['reason']);
        $this->assertStringContainsString('2343 МиБ', $result['killed'][0]['detail']);
    }

    public function test_a_leaking_daemon_is_reaped_by_descriptor_count(): void
    {
        // 2 774 дескриптора, все на один madelineproto.log — vendor-течь,
        // которую нельзя починить, но можно ограничить.
        $probe = new FakeDaemonProcessProbe;
        $probe->add(2557501, fds: 2774);
        $factory = new SpawningFactoryStub($probe);

        $result = $this->supervisor($probe, $factory)->tick();

        $this->assertSame(MadelineDaemonSupervisor::REASON_FDS, $result['killed'][0]['reason']);
        $this->assertStringContainsString('madelineproto.log', $result['killed'][0]['detail']);
    }

    public function test_an_ancient_daemon_is_recycled(): void
    {
        $probe = new FakeDaemonProcessProbe;
        $probe->add(2557501, ageS: 33 * 3600);
        $factory = new SpawningFactoryStub($probe);

        $result = $this->supervisor($probe, $factory)->tick();

        $this->assertSame(MadelineDaemonSupervisor::REASON_AGE, $result['killed'][0]['reason']);
    }

    public function test_a_daemon_that_ignores_sigterm_gets_sigkill(): void
    {
        // Его shutdown-путь падает в Revolt\EventLoop DriverSuspension, и TERM
        // он игнорировал 20 секунд. TERM без последующего KILL здесь — это
        // «сторож постучался и ушёл».
        $probe = new FakeDaemonProcessProbe;
        $probe->add(2557501, FakeDaemonProcessProbe::CRON_CGROUP);
        $probe->ignoresTerm = [2557501];
        $factory = new SpawningFactoryStub($probe);

        $this->supervisor($probe, $factory)->tick();

        $this->assertSame([2557501], $probe->signalledWith(15));
        $this->assertSame([2557501], $probe->signalledWith(9));
    }

    public function test_no_daemon_at_all_spawns_one_from_our_cgroup(): void
    {
        $probe = new FakeDaemonProcessProbe;
        $factory = new SpawningFactoryStub($probe);

        $result = $this->supervisor($probe, $factory)->tick();

        $this->assertTrue($result['spawned']);
        $this->assertSame(1, $factory->opened);
        $this->assertSame(FakeDaemonProcessProbe::OWN_CGROUP, $result['own_cgroup']);
    }

    public function test_a_failing_spawn_is_reported_and_not_thrown(): void
    {
        // Сторож, роняющий сам себя, хуже отсутствующего: systemd перезапустил
        // бы юнит по кругу, и в journal осталась бы одна и та же трассировка.
        $probe = new FakeDaemonProcessProbe;
        $factory = new FailingFactoryStub;

        $result = $this->supervisor($probe, $factory)->tick();

        $this->assertFalse($result['spawned']);
        $this->assertStringContainsString('AUTH_RESTART', (string) $result['spawn_error']);
    }

    public function test_an_unconfigured_host_does_not_pretend_to_spawn(): void
    {
        $probe = new FakeDaemonProcessProbe;
        $factory = new UnconfiguredFactoryStub;

        $result = $this->supervisor($probe, $factory)->tick();

        $this->assertFalse($result['spawned']);
        $this->assertStringContainsString('не настроен', (string) $result['spawn_error']);
    }

    public function test_without_a_readable_cgroup_nothing_is_killed(): void
    {
        // Не Linux / нет /proc: fail-open. Убивать демона по незнанию нельзя.
        $probe = new FakeDaemonProcessProbe;
        $probe->ownCgroup = null;
        $probe->add(2695029, FakeDaemonProcessProbe::CRON_CGROUP);
        $factory = new SpawningFactoryStub($probe);

        $result = $this->supervisor($probe, $factory)->tick();

        $this->assertSame([], $result['killed']);
        $this->assertSame([2695029], $result['healthy']);
    }
}

/**
 * Фабрика, «поднимающая» демон: регистрирует новый процесс в нашей cgroup —
 * ровно так же, как настоящий spawn наследует cgroup породившего процесса.
 */
final class SpawningFactoryStub extends MadelineClientFactory
{
    public int $opened = 0;

    public function __construct(private readonly FakeDaemonProcessProbe $probe) {}

    public function isConfigured(): bool
    {
        return true;
    }

    public function open(?string $clientClass = null): object
    {
        $this->opened++;
        $this->probe->add(9_000_000 + $this->opened);

        return new \stdClass;
    }
}

final class FailingFactoryStub extends MadelineClientFactory
{
    public function isConfigured(): bool
    {
        return true;
    }

    public function open(?string $clientClass = null): object
    {
        throw new RuntimeException('AUTH_RESTART: сессия требует повторного входа');
    }
}

final class UnconfiguredFactoryStub extends MadelineClientFactory
{
    public function isConfigured(): bool
    {
        return false;
    }
}
