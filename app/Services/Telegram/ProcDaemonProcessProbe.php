<?php

declare(strict_types=1);

namespace App\Services\Telegram;

/**
 * Живая реализация {@see DaemonProcessProbe} поверх /proc и posix_kill.
 *
 * Всё читается из /proc, а не из `ps`: `ps` на этом контейнере обрезает
 * командную строку и не показывает cgroup вовсе, а именно cgroup — то
 * единственное поле, по которому 19-08-2026 удалось отличить демон, живущий за
 * счёт бюджета cron.service, от здорового.
 *
 * Fail-open везде: не Linux, нет /proc, нет posix — вернём null/пусто, и
 * супервизор просто ничего не предпримет. Сторож, который падает сам, вреднее
 * отсутствующего.
 */
class ProcDaemonProcessProbe implements DaemonProcessProbe
{
    public function pidsMatching(string $pattern): array
    {
        if (! function_exists('exec')) {
            return [];
        }

        $lines = [];
        @exec('pgrep -f -- '.escapeshellarg($pattern).' 2>/dev/null', $lines);

        $pids = [];
        foreach ($lines as $line) {
            $pid = (int) trim($line);
            if ($pid > 0) {
                $pids[] = $pid;
            }
        }

        return $pids;
    }

    public function cgroupOf(int $pid): ?string
    {
        $raw = @file_get_contents("/proc/{$pid}/cgroup");
        if ($raw === false) {
            return null;
        }

        // cgroup v2: единственная строка вида «0::/system.slice/cron.service».
        // На v1 строк много; берём унифицированную (префикс «0::»), а если её
        // нет — первую, чем-то это лучше, чем ничего.
        foreach (preg_split('/\r\n|\n|\r/', $raw) ?: [] as $line) {
            if (str_starts_with($line, '0::')) {
                return substr($line, 3);
            }
        }

        return null;
    }

    public function ownCgroup(): ?string
    {
        return $this->cgroupOf(function_exists('getmypid') ? (int) getmypid() : 0);
    }

    public function rssKbOf(int $pid): ?int
    {
        $raw = @file_get_contents("/proc/{$pid}/status");
        if ($raw === false) {
            return null;
        }
        if (preg_match('/^VmRSS:\s+(\d+)\s+kB/m', $raw, $m) !== 1) {
            return null;
        }

        return (int) $m[1];
    }

    public function fdCountOf(int $pid): ?int
    {
        // scandir, а не glob: дескрипторы — симлинки, часть из них битые
        // (удалённые файлы), и glob такие молча пропускает — счёт занижался бы
        // ровно на самых интересных.
        $entries = @scandir("/proc/{$pid}/fd");
        if ($entries === false) {
            return null;
        }

        return max(0, count($entries) - 2); // «.» и «..»
    }

    public function ageSecondsOf(int $pid): ?int
    {
        $mtime = @filemtime("/proc/{$pid}");
        if ($mtime === false) {
            return null;
        }

        return max(0, time() - $mtime);
    }

    public function signal(int $pid, int $signal): bool
    {
        if (! function_exists('posix_kill')) {
            return false;
        }

        return (bool) @posix_kill($pid, $signal);
    }

    public function isAlive(int $pid): bool
    {
        return $pid > 0 && @file_exists("/proc/{$pid}");
    }

    public function sleep(int $seconds): void
    {
        if ($seconds > 0) {
            sleep($seconds);
        }
    }
}
