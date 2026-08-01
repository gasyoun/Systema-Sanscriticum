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
        // 1) crontab -u USER -l — работает от root / с CAP_SETUID.
        $out = $this->run(['crontab', '-u', $user, '-l']);
        if ($out !== null && trim($out) !== '') {
            return $out;
        }

        // 2) Bare `crontab -l` — ТОЛЬКО если мы и есть этот user.
        //    Раньше его звали всегда: www-data, читая «root», получал СВОЙ
        //    crontab (без systema-auto-deploy-run.sh) → soft-сбой каждые 15 мин
        //    при живом авто-деплое (H1933 false positive, 30-07-2026).
        $current = $this->currentUsername();
        if ($current !== null && $current === $user) {
            $out = $this->run(['crontab', '-l']);
            if ($out !== null && trim($out) !== '') {
                return $out;
            }
        }

        // 3) Спул — 600 root:crontab, www-data обычно не читает.
        $spool = $this->fileContents('/var/spool/cron/crontabs/'.$user);
        if ($spool !== null && trim($spool) !== '') {
            return $spool;
        }

        // 4) Зеркало, которое пишут deploy.sh / server_guards_apply.sh (644),
        //    чтобы cabinet:probe от www-data видел root-крон авто-деплоя.
        return $this->fileContents(base_path('storage/app/server_guards/crontab-'.$user.'.installed'));
    }

    private function currentUsername(): ?string
    {
        if (\function_exists('posix_geteuid') && \function_exists('posix_getpwuid')) {
            $info = @posix_getpwuid(posix_geteuid());
            if (\is_array($info) && isset($info['name']) && $info['name'] !== '') {
                return (string) $info['name'];
            }
        }

        $who = $this->run(['whoami']);

        return $who === null ? null : trim($who);
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

    public function trackedDirtyPaths(string $repoDir): ?array
    {
        if (! is_dir($repoDir)) {
            return null;
        }
        $gitMeta = rtrim($repoDir, '/\\').DIRECTORY_SEPARATOR.'.git';
        if (! is_dir($gitMeta) && ! is_file($gitMeta)) {
            return null;
        }

        try {
            $result = Process::timeout($this->timeout)
                ->path($repoDir)
                ->run(['git', 'status', '--porcelain', '--untracked-files=no']);
        } catch (Throwable) {
            return null;
        }

        if (! $result->successful()) {
            return null;
        }

        $paths = [];
        foreach (preg_split('/\r\n|\n|\r/', $result->output()) ?: [] as $line) {
            if ($line === '') {
                continue;
            }
            // porcelain v1: XY<space>path  or  XY<space>orig -> path
            $path = trim(substr($line, 3));
            if (str_contains($path, ' -> ')) {
                $path = trim(explode(' -> ', $path, 2)[1]);
            }
            if ($path !== '') {
                $paths[] = $path;
            }
        }

        return $paths;
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
