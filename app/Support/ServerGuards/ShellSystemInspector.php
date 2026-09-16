<?php

declare(strict_types=1);

namespace App\Support\ServerGuards;

use App\Support\Backup\SplitGroupMath;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Живая машина: файлы, systemd, crontab. Только чтение — ни одна проверка
 * ничего не применяет (применяет scripts/server_guards_apply.sh, и только он).
 */
final class ShellSystemInspector implements SystemInspector
{
    /** Как долго держать ответ off-site назначения, секунд. */
    private const BACKUP_PROBE_TTL_SECONDS = 3600;

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

    public function failedUnits(): ?array
    {
        // --plain: без него systemctl рисует «●» перед именем, и юнит уезжает
        // в тревогу с бусиной в названии.
        $out = $this->run(['systemctl', '--failed', '--no-legend', '--plain', '--no-pager']);
        if ($out === null) {
            return null; // Нет systemd / команда не отработала — спросить нечем.
        }

        $units = [];
        foreach (preg_split('/\r\n|\n|\r/', $out) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            // «samudra-health-monitor.service loaded failed failed Описание»
            $name = preg_split('/\s+/', $line)[0] ?? '';
            if ($name !== '') {
                $units[] = $name;
            }
        }

        return $units;
    }

    public function backupDestinations(): ?array
    {
        // Каждые 15 минут (cabinet:probe) ходить по WebDAV нельзя: одна
        // зависшая выдача остановила бы всю проверку предохранителей. Час —
        // достаточно мелкое зерно для порога в 8 СУТОК и достаточно редкое,
        // чтобы off-site не превратился в источник нагрузки.
        try {
            return Cache::remember(
                'server_guards.backup_destinations',
                self::BACKUP_PROBE_TTL_SECONDS,
                fn (): ?array => $this->readBackupDestinations(),
            );
        } catch (Throwable) {
            // Кеш недоступен (redis лёг) — спросим напрямую, но молча.
            try {
                return $this->readBackupDestinations();
            } catch (Throwable) {
                return null;
            }
        }
    }

    /**
     * @return list<array{disk: string, reachable: bool, newestAt: int|null, newestBytes: int|null}>|null
     */
    private function readBackupDestinations(): ?array
    {
        /** @var list<string> $disks */
        $disks = (array) config('backup.backup.destination.disks', []);
        // Off-site нога переехала из destination.disks в split_upload (Yandex
        // WebDAV режет PUT >1 ГБ — spatie туда больше не пишет напрямую), но
        // свежесть частей на этом диске аудитить по-прежнему обязательно.
        $splitDisk = (string) config('backup.backup.split_upload.disk', '');
        if ($splitDisk !== '' && ! in_array($splitDisk, $disks, true)) {
            $disks[] = $splitDisk;
        }
        $name = (string) config('backup.backup.name', '');
        if ($disks === [] || $name === '') {
            return null;
        }

        // 0bn (15-09-2026): аудит читает только ЛИСТИНГ, поэтому WebDAV-диски
        // читаются через *_readonly_probe близнеца (curl-потолок 45 с, клиент в
        // AppServiceProvider), а не через боевой диск с PUT-потолком 300 с —
        // stash cache-miss здесь не может держать probe >120 с (WATCHDOG TIMEOUT
        // 14-09 ×5 + 15-09 repro). local и прочие не-WebDAV диски идут как есть.
        $readDiskOf = [];
        foreach ($disks as $disk) {
            $probeDisk = $disk.'_readonly_probe';
            $readDiskOf[$disk] = (string) config("filesystems.disks.$probeDisk.driver", '') !== ''
                ? $probeDisk
                : $disk;
        }

        $rows = [];
        // 0bn (16-09-2026): потолок на ШАГ, а не только на запрос. Замер на
        // проде: spatie-обход (BackupDestination::backups + sizeInBytes по
        // каждому из 476 файлов) = 144 с, а ОДИН shallow-листинг адаптера того
        // же каталога = 2.8 с с теми же path/size/lastModified. Поэтому читаем
        // сразу метаданные листинга, а бюджет добивает патологию: если листинг
        // всё же не уложился — уходим из метода без строк (проба остаётся
        // быстрой, следующий такт повторит; ложной находки не пишем).
        $budgetSeconds = max(5, (int) config('server_guards.backup_probe_budget_seconds', 45));
        $deadline = microtime(true) + $budgetSeconds;

        foreach ($disks as $disk) {
            $disk = (string) $disk;
            try {
                $storage = Storage::disk($readDiskOf[$disk]);
                $entries = [];
                foreach ($storage->getAdapter()->listContents($name, false) as $item) {
                    if (! $item->isFile()) {
                        continue;
                    }

                    $base = basename($item->path());
                    $entries[] = [
                        'name' => $base,
                        'timestamp' => SplitGroupMath::timestampFromBasename($base) ?? (int) ($item->lastModified() ?? 0),
                        'bytes' => (int) ($item->fileSize() ?? 0),
                    ];

                    if (microtime(true) > $deadline) {
                        // Шаг не уложился в бюджет — прогон неполный, молчим до
                        // следующего такта вместо ложного «нет архива».
                        return null;
                    }
                }

                // H3371: свежесть off-site диска сплита меряется только ПОЛНОЙ
                // группой частей. Одинокая часть ровно max_part_mb байт
                // проходит порог BACKUP_MIN_ARCHIVE_MB и читалась как живой
                // off-site, хотя архив из неё не собирается. Нет полной
                // группы — нет и свежести: «на диске нет ни одного архива».
                $newestAt = null;
                $newestBytes = null;
                if ($entries !== []) {
                    if ($disk === $splitDisk) {
                        $complete = SplitGroupMath::newestCompleteEntry($entries);
                        if ($complete !== null) {
                            $newestAt = $complete['timestamp'];
                            $newestBytes = $complete['bytes'];
                        }
                    } else {
                        foreach ($entries as $entry) {
                            if ($newestAt === null || $entry['timestamp'] > $newestAt) {
                                $newestAt = $entry['timestamp'];
                                $newestBytes = $entry['bytes'];
                            }
                        }
                    }
                }

                $rows[] = [
                    'disk' => $disk,
                    'reachable' => true,
                    'newestAt' => $newestAt,
                    'newestBytes' => $newestBytes,
                ];
            } catch (Throwable) {
                // Отдельное назначение не ответило — это и есть «недостижимо»,
                // а не повод потерять сведения об остальных.
                $rows[] = ['disk' => $disk, 'reachable' => false, 'newestAt' => null, 'newestBytes' => null];
            }
        }

        return $rows;
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
