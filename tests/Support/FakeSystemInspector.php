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
        ];

        $fake->phpCliMemoryLimit = $spec->get('PHP_CLI_MEMORY_LIMIT');

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
}
