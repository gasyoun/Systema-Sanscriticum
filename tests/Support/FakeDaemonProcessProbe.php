<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Telegram\DaemonProcessProbe;

/**
 * Машина с процессами — в памяти. Существует затем же, зачем
 * {@see FakeSystemInspector}: вердикт «этот демон живёт не в своей cgroup»
 * обязан проверяться без Linux, /proc и systemd.
 */
final class FakeDaemonProcessProbe implements DaemonProcessProbe
{
    public const OWN_CGROUP = '/system.slice/systema-madeline-daemon.service';

    public const CRON_CGROUP = '/system.slice/cron.service';

    public ?string $ownCgroup = self::OWN_CGROUP;

    /** @var array<int, array{cgroup: ?string, rss_kb: ?int, fds: ?int, age_s: ?int}> */
    public array $processes = [];

    /** @var list<array{pid: int, signal: int}> */
    public array $signals = [];

    /** Пиды, которые НЕ умирают от SIGTERM — как зависший демон 19-08-2026. */
    public array $ignoresTerm = [];

    public int $slept = 0;

    public function add(int $pid, string $cgroup = self::OWN_CGROUP, int $rssKb = 108_000, int $fds = 118, int $ageS = 3600): void
    {
        $this->processes[$pid] = ['cgroup' => $cgroup, 'rss_kb' => $rssKb, 'fds' => $fds, 'age_s' => $ageS];
    }

    public function pidsMatching(string $pattern): array
    {
        return array_map(intval(...), array_keys($this->processes));
    }

    public function cgroupOf(int $pid): ?string
    {
        return $this->processes[$pid]['cgroup'] ?? null;
    }

    public function ownCgroup(): ?string
    {
        return $this->ownCgroup;
    }

    public function rssKbOf(int $pid): ?int
    {
        return $this->processes[$pid]['rss_kb'] ?? null;
    }

    public function fdCountOf(int $pid): ?int
    {
        return $this->processes[$pid]['fds'] ?? null;
    }

    public function ageSecondsOf(int $pid): ?int
    {
        return $this->processes[$pid]['age_s'] ?? null;
    }

    public function signal(int $pid, int $signal): bool
    {
        $this->signals[] = ['pid' => $pid, 'signal' => $signal];

        if ($signal === 15 && in_array($pid, $this->ignoresTerm, true)) {
            return true; // сигнал доставлен, процесс его игнорирует
        }
        unset($this->processes[$pid]);

        return true;
    }

    public function isAlive(int $pid): bool
    {
        return array_key_exists($pid, $this->processes);
    }

    public function sleep(int $seconds): void
    {
        $this->slept += $seconds;
    }

    /**
     * @return list<int>
     */
    public function signalledWith(int $signal): array
    {
        return array_values(array_map(
            static fn (array $s): int => $s['pid'],
            array_filter($this->signals, static fn (array $s): bool => $s['signal'] === $signal),
        ));
    }
}
