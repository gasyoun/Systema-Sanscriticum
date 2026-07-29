<?php

declare(strict_types=1);

namespace App\Support\ServerGuards;

use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Живая машина: файлы, systemd, crontab. Только чтение — ни одна проверка
 * ничего не применяет (применяет scripts/server_guards_apply.sh, и только он).
 */
final class ShellSystemInspector implements SystemInspector
{
    /** @var array<string, string|null> */
    private array $unitPropertyCache = [];

    public function __construct(private readonly int $timeout = 10) {}

    public function fileContents(string $path): ?string
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }
        $raw = @file_get_contents($path);

        return $raw === false ? null : $raw;
    }

    public function fileExists(string $path): bool
    {
        return is_file($path);
    }

    public function fileMode(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }
        $perms = @fileperms($path);

        return $perms === false ? null : substr(sprintf('%o', $perms), -3);
    }

    public function crontabFor(string $user): ?string
    {
        // Под root ходим через -u; под самим www-data `crontab -u` на Debian
        // отказывает, зато свой crontab читается без него. Последний рубеж —
        // файл спула, читаемый владельцем.
        $attempts = [
            ['crontab', '-u', $user, '-l'],
            ['crontab', '-l'],
        ];
        foreach ($attempts as $cmd) {
            $out = $this->run($cmd);
            if ($out !== null && trim($out) !== '') {
                return $out;
            }
        }

        return $this->fileContents('/var/spool/cron/crontabs/'.$user);
    }

    public function unitIsActive(string $unit): bool
    {
        return trim((string) $this->run(['systemctl', 'is-active', $unit])) === 'active';
    }

    public function unitProperty(string $unit, string $property): ?string
    {
        $key = $unit.'|'.$property;
        if (array_key_exists($key, $this->unitPropertyCache)) {
            return $this->unitPropertyCache[$key];
        }

        $out = $this->run(['systemctl', 'show', $unit, '-p', $property, '--value']);
        $value = $out === null ? null : trim($out);

        return $this->unitPropertyCache[$key] = ($value === '' ? null : $value);
    }

    public function phpCliMemoryLimit(): string
    {
        // Эта команда САМА идёт в CLI SAPI, так что ini_get здесь — и есть
        // действующее значение того потолка, который проверяется.
        return (string) ini_get('memory_limit');
    }

    public function globFiles(string $pattern): array
    {
        $found = glob($pattern) ?: [];

        return array_values(array_filter($found, 'is_file'));
    }

    public function swapTotalBytes(): ?int
    {
        $meminfo = $this->fileContents('/proc/meminfo');
        if ($meminfo === null || preg_match('/^SwapTotal:\s+(\d+)\s+kB/m', $meminfo, $m) !== 1) {
            return null;
        }

        return (int) $m[1] * 1024;
    }

    /**
     * @param  list<string>  $command
     */
    private function run(array $command): ?string
    {
        try {
            $result = Process::timeout($this->timeout)->run($command);
        } catch (Throwable) {
            return null;
        }

        return $result->successful() ? $result->output() : null;
    }
}
