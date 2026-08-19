<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\ServerGuards\GuardSpec;
use App\Support\ServerGuards\SystemInspector;

/**
 * Здоровый прод в памяти — и способ по одной снимать с него предохранители.
 *
 * Здоровое состояние строится ИЗ РЕАЛЬНЫХ шаблонов репозитория, поэтому тест,
 * который ждёт «ноль находок», заодно доказывает, что манифест, конфиг значений и
 * шаблоны согласованы между собой и подставляются без остатка.
 */
final class FakeSystemInspector implements SystemInspector
{
    /** @var array<string, string> */
    public array $files = [];

    /** @var array<string, string> */
    public array $modes = [];

    /** @var array<string, string> */
    public array $crontabs = [];

    /** @var array<string, bool> */
    public array $active = [];

    /** @var array<string, string> */
    public array $unitProperties = [];

    public string $phpCliMemoryLimit = '768M';

    public ?int $swapTotalBytes = 4294967296;

    /** @var list<string>|null null = git unavailable; [] = clean tree */
    public ?array $trackedDirtyPaths = [];

    /** @var list<string>|null null = спросить нечем; [] = ни один юнит не упал */
    public ?array $failedUnits = [];

    /** @var list<array{disk: string, reachable: bool, newestAt: int|null, newestBytes: int|null}>|null */
    public ?array $backupDestinations = null;

    public static function healthy(GuardSpec $spec, string $templateRoot, string $manifestBody): self
    {
        $fake = new self;

        foreach (preg_split('/\r\n|\n|\r/', $manifestBody) ?: [] as $line) {
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            [$template, $dest, $mode] = explode('|', $line);
            $body = (string) file_get_contents(rtrim($templateRoot, '/\\').'/'.$template);
            $fake->files[$spec->render($dest)] = $spec->render($body);
            $fake->modes[$spec->render($dest)] = $mode;
        }

        $fake->crontabs[$spec->get('APP_USER')] = $spec->render(
            (string) file_get_contents(rtrim($templateRoot, '/\\').'/cron/app-user.crontab')
        );
        $fake->crontabs['root'] = $spec->render(
            (string) file_get_contents(rtrim($templateRoot, '/\\').'/cron/root.crontab')
        );

        $pool = '/etc/php/'.$spec->get('PHP_VERSION').'/fpm/pool.d/www.conf';
        $fake->files[$pool] = "[www]\npm = dynamic\npm.max_children = ".$spec->get('FPM_MAX_CHILDREN')
            ."\npm.max_requests = ".$spec->get('FPM_MAX_REQUESTS')."\n";

        foreach (array_merge($spec->csv('REQUIRED_ACTIVE_UNITS'), $spec->csv('REQUIRED_ACTIVE_TIMERS')) as $unit) {
            $fake->active[$unit] = true;
        }

        $fake->unitProperties = [
            'cron|MemoryHigh' => (string) $spec->bytes('CRON_MEMORY_HIGH'),
            'cron|MemoryMax' => (string) $spec->bytes('CRON_MEMORY_MAX'),
            'cron|TasksMax' => $spec->get('CRON_TASKS_MAX'),
            'cron|OOMPolicy' => 'kill',
            'cron|Restart' => 'always',
            'supervisor|MemoryHigh' => (string) $spec->bytes('SUPERVISOR_MEMORY_HIGH'),
            'supervisor|MemoryMax' => (string) $spec->bytes('SUPERVISOR_MEMORY_MAX'),
            // H3121: у демона MadelineProto свой юнит и свой бюджет.
            'systema-madeline-daemon|MemoryHigh' => (string) $spec->bytes('MADELINE_MEMORY_HIGH'),
            'systema-madeline-daemon|MemoryMax' => (string) $spec->bytes('MADELINE_MEMORY_MAX'),
            'systema-madeline-daemon|TasksMax' => $spec->get('MADELINE_TASKS_MAX'),
            'systema-madeline-daemon|OOMPolicy' => 'kill',
        ];

        // H3121: здоровый прод — группа крона далеко под порогом, а отметка
        // завершённого schedule:run свежая. Тест на «ноль находок» обязан
        // проверять и это: обе проверки молчаливы по построению, и без явного
        // здорового значения они молчали бы всегда, в том числе и сломанные.
        $fake->files['/sys/fs/cgroup/system.slice/cron.service/memory.current'] =
            (string) intdiv($spec->bytes('CRON_MEMORY_HIGH'), 10)."\n";
        $fake->files[rtrim($spec->get('APP_DIR'), '/').'/storage/framework/schedule-run.stamp'] =
            (string) time()."\n";

        $fake->phpCliMemoryLimit = $spec->get('PHP_CLI_MEMORY_LIMIT');

        // H3181: здоровый прод — /tmp с явным потолком, ни одного упавшего
        // юнита, свежие и правдоподобные копии на обоих назначениях. Все три
        // проверки молчаливы по построению (fail-open), и без явного здорового
        // значения они молчали бы всегда — в том числе и сломанные.
        $fake->files['/proc/mounts'] =
            "sysfs /sys sysfs rw,nosuid,nodev,noexec,relatime 0 0\n"
            .'tmpfs /tmp tmpfs rw,nosuid,nodev,size='
            .intdiv($spec->bytes('TMP_TMPFS_SIZE'), 1024)."k,nr_inodes=1048576,mode=1777 0 0\n";

        $plausible = ((int) $spec->get('BACKUP_MIN_ARCHIVE_MB') + 1) * 1024 ** 2;
        $fake->backupDestinations = [
            ['disk' => 'local', 'reachable' => true, 'newestAt' => time() - 3600, 'newestBytes' => $plausible],
            ['disk' => 'yandex_disk', 'reachable' => true, 'newestAt' => time() - 3600, 'newestBytes' => $plausible],
        ];

        return $fake;
    }

    public function fileContents(string $path): ?string
    {
        return $this->files[$path] ?? null;
    }

    public function fileExists(string $path): bool
    {
        return array_key_exists($path, $this->files);
    }

    public function fileMode(string $path): ?string
    {
        return $this->modes[$path] ?? null;
    }

    public function crontabFor(string $user): ?string
    {
        return $this->crontabs[$user] ?? null;
    }

    public function unitIsActive(string $unit): bool
    {
        return $this->active[$unit] ?? false;
    }

    public function unitProperty(string $unit, string $property): ?string
    {
        return $this->unitProperties[$unit.'|'.$property] ?? null;
    }

    public function phpCliMemoryLimit(): string
    {
        return $this->phpCliMemoryLimit;
    }

    public function globFiles(string $pattern): array
    {
        $regex = '/^'.str_replace(['\*', '\?'], ['[^\/]*', '.'], preg_quote($pattern, '/')).'$/';

        return array_values(array_filter(
            array_keys($this->files),
            static fn (string $path): bool => preg_match($regex, $path) === 1,
        ));
    }

    public function swapTotalBytes(): ?int
    {
        return $this->swapTotalBytes;
    }

    public function trackedDirtyPaths(string $repoDir): ?array
    {
        return $this->trackedDirtyPaths;
    }

    public function failedUnits(): ?array
    {
        return $this->failedUnits;
    }

    public function backupDestinations(): ?array
    {
        return $this->backupDestinations;
    }
}
