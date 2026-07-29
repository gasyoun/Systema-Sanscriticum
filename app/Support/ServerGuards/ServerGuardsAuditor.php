<?php

declare(strict_types=1);

namespace App\Support\ServerGuards;

use RuntimeException;

/**
 * Сверяет живую машину с scripts/server_guards.conf + scripts/server_guards/.
 *
 * Проверяет ПРИСУТСТВИЕ и КОРРЕКТНОСТЬ, а не применяет: пропажа предохранителя
 * должна порождать тревогу, а не выясняться следующей аварией
 * (docs/server-resource-guards.md, handoff H1914).
 */
final class ServerGuardsAuditor
{
    private const MEMORY_DROP_IN = 'memory-cap.conf';

    public function __construct(
        private readonly GuardSpec $spec,
        private readonly SystemInspector $sys,
        private readonly string $templateRoot,
    ) {}

    /**
     * @return list<GuardFinding>
     */
    public function audit(): array
    {
        return array_merge(
            $this->auditManagedFiles(),
            $this->auditCrontab(),
            $this->auditUnitLimits(),
            $this->auditSingleMemoryDefinition(),
            $this->auditPhp(),
            $this->auditRequiredUnits(),
            $this->auditEarlyoomArgs(),
            $this->auditSwap(),
        );
    }

    /**
     * @param  list<GuardFinding>  $findings
     */
    public static function hasBlocking(array $findings): bool
    {
        foreach ($findings as $finding) {
            if ($finding->severity !== GuardFinding::INFO) {
                return true;
            }
        }

        return false;
    }

    /**
     * Манифест — общий с applier'ом список управляемых файлов.
     *
     * @return list<array{template: string, dest: string, mode: string, severity: string}>
     */
    public function manifest(): array
    {
        $path = rtrim($this->templateRoot, '/\\').'/manifest.psv';
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("не читается манифест: {$path}");
        }

        $rows = [];
        // \r\n|\n|\r, а не \R — см. GuardSpec::fromString: \R без /u режет UTF-8.
        foreach (preg_split('/\r\n|\n|\r/', $raw) ?: [] as $line) {
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = explode('|', $line);
            if (count($parts) !== 4) {
                throw new RuntimeException("манифест: битая строка «{$line}»");
            }
            $rows[] = [
                'template' => $parts[0],
                'dest' => $this->spec->render($parts[1]),
                'mode' => $parts[2],
                'severity' => $parts[3] === GuardFinding::CRITICAL ? GuardFinding::CRITICAL : GuardFinding::WARNING,
            ];
        }

        return $rows;
    }

    /**
     * Пропажа файла — по важности из манифеста; расхождение содержимого — всегда
     * warning: файл есть, но кто-то правил его руками, минуя репозиторий.
     *
     * @return list<GuardFinding>
     */
    private function auditManagedFiles(): array
    {
        $findings = [];
        foreach ($this->manifest() as $row) {
            $dest = $row['dest'];
            $actual = $this->sys->fileContents($dest);
            if ($actual === null) {
                $findings[] = new GuardFinding(
                    $row['severity'],
                    'managed-file',
                    "{$dest} отсутствует (вернуть: scripts/server_guards_apply.sh)",
                );

                continue;
            }

            $templatePath = rtrim($this->templateRoot, '/\\').'/'.$row['template'];
            $template = @file_get_contents($templatePath);
            if ($template === false) {
                $findings[] = GuardFinding::warning('managed-file', "нет шаблона {$templatePath} — сверить не с чем");

                continue;
            }

            if ($this->normalize($this->spec->render($template)) !== $this->normalize($actual)) {
                $findings[] = GuardFinding::warning(
                    'managed-file',
                    "{$dest} разошёлся с репозиторием (правка руками мимо scripts/server_guards/{$row['template']})",
                );
            }

            $mode = $this->sys->fileMode($dest);
            if ($mode !== null && $mode !== $row['mode']) {
                $findings[] = GuardFinding::warning('managed-file', "{$dest}: права {$mode}, ожидались {$row['mode']}");
            }
        }

        return $findings;
    }

    /**
     * @return list<GuardFinding>
     */
    private function auditCrontab(): array
    {
        $user = $this->spec->get('APP_USER');
        $crontab = $this->sys->crontabFor($user);
        if ($crontab === null || trim($crontab) === '') {
            return [GuardFinding::critical('crontab', "crontab {$user} пуст — планировщика и сторожей нет вовсе")];
        }

        $lines = [];
        foreach (preg_split('/\r\n|\n|\r/', $crontab) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            $lines[] = $trimmed;
        }

        $findings = [];
        $hasWrapper = false;
        $hasBare = false;
        foreach ($lines as $line) {
            if (str_contains($line, 'systema-schedule-run.sh')) {
                $hasWrapper = true;
            } elseif (preg_match('/artisan\s+schedule:run/', $line) === 1) {
                $hasBare = true;
            }
        }

        if (! $hasWrapper) {
            $findings[] = GuardFinding::critical(
                'crontab',
                "в crontab {$user} нет строки через /usr/local/sbin/systema-schedule-run.sh — планировщик снова может копить прогоны",
            );
        }
        if ($hasBare) {
            $findings[] = GuardFinding::critical(
                'crontab',
                "в crontab {$user} есть голый `artisan schedule:run` — именно эта форма выела память 23-07 и 28-07-2026",
            );
        }

        $watchdogs = [
            ['cabinet:probe', 'WATCHDOG_CABINET_SCHEDULE', 'WATCHDOG_CABINET_MAX_SECONDS'],
            ['heartbeat:ping', 'WATCHDOG_HEARTBEAT_SCHEDULE', 'WATCHDOG_HEARTBEAT_MAX_SECONDS'],
        ];
        foreach ($watchdogs as [$command, $scheduleKey, $timeoutKey]) {
            $matched = null;
            foreach ($lines as $line) {
                if (str_contains($line, 'systema-watchdog-run.sh') && str_contains($line, $command)) {
                    $matched = $line;
                    break;
                }
            }
            if ($matched === null) {
                $findings[] = GuardFinding::critical(
                    'watchdog-cron',
                    "нет отдельной строки cron для {$command} — сторож снова окажется внутри того, что сторожит (28-07: тревога умерла на час раньше сайта)",
                );

                continue;
            }
            $schedule = $this->spec->get($scheduleKey);
            if (! str_starts_with($matched, $schedule)) {
                $findings[] = GuardFinding::warning(
                    'watchdog-cron',
                    "{$command}: расписание не «{$schedule}» — строка «{$matched}»",
                );
            }
            if (! preg_match('/\b'.preg_quote($this->spec->get($timeoutKey), '/').'\b/', $matched)) {
                $findings[] = GuardFinding::warning(
                    'watchdog-cron',
                    "{$command}: таймаут не {$this->spec->get($timeoutKey)} с — строка «{$matched}»",
                );
            }
        }

        return $findings;
    }

    /**
     * @return list<GuardFinding>
     */
    private function auditUnitLimits(): array
    {
        $findings = [];

        $expectations = [
            ['cron', 'MemoryHigh', (string) $this->spec->bytes('CRON_MEMORY_HIGH'), GuardFinding::CRITICAL],
            ['cron', 'MemoryMax', (string) $this->spec->bytes('CRON_MEMORY_MAX'), GuardFinding::CRITICAL],
            ['cron', 'TasksMax', $this->spec->get('CRON_TASKS_MAX'), GuardFinding::CRITICAL],
            ['cron', 'OOMPolicy', 'kill', GuardFinding::CRITICAL],
            ['cron', 'Restart', 'always', GuardFinding::WARNING],
            ['supervisor', 'MemoryHigh', (string) $this->spec->bytes('SUPERVISOR_MEMORY_HIGH'), GuardFinding::CRITICAL],
            ['supervisor', 'MemoryMax', (string) $this->spec->bytes('SUPERVISOR_MEMORY_MAX'), GuardFinding::CRITICAL],
        ];

        foreach ($expectations as [$unit, $property, $expected, $severity]) {
            $actual = $this->sys->unitProperty($unit, $property);
            if ($actual === null) {
                $findings[] = new GuardFinding($severity, 'systemd', "{$unit}.service: {$property} не читается");

                continue;
            }
            if ($actual === $expected) {
                continue;
            }
            // «infinity» — это буквально «потолка нет», то есть предохранителя нет.
            $note = in_array(strtolower($actual), ['infinity', 'max', ''], true)
                ? ' — потолка НЕТ'
                : '';
            $findings[] = new GuardFinding(
                $severity,
                'systemd',
                "{$unit}.service: {$property}={$actual}, ожидалось {$expected}{$note}",
            );
        }

        return $findings;
    }

    /**
     * Ловушка 29-07: два drop-in'а с MemoryMax → эффективное значение решает
     * сортировка имён файлов, а не замысел. Числа обязаны жить в одном файле.
     *
     * @return list<GuardFinding>
     */
    private function auditSingleMemoryDefinition(): array
    {
        $findings = [];
        foreach (['cron', 'supervisor'] as $unit) {
            $dir = "/etc/systemd/system/{$unit}.service.d";
            $definers = [];
            foreach ($this->sys->globFiles($dir.'/*.conf') as $file) {
                $body = $this->sys->fileContents($file);
                if ($body === null) {
                    continue;
                }
                $active = preg_replace('/^\s*#.*$/m', '', $body) ?? $body;
                if (preg_match('/^\s*Memory(High|Max)\s*=/m', $active) === 1) {
                    $definers[] = basename($file);
                }
            }
            $extra = array_values(array_diff($definers, [self::MEMORY_DROP_IN]));
            if ($extra !== []) {
                $findings[] = GuardFinding::critical(
                    'single-definition',
                    "{$dir}: числа памяти задаёт не только ".self::MEMORY_DROP_IN.', а ещё '.implode(', ', $extra)
                    .' — эффективное значение решит сортировка имён, а не замысел',
                );
            }
        }

        return $findings;
    }

    /**
     * @return list<GuardFinding>
     */
    private function auditPhp(): array
    {
        $findings = [];

        $limit = trim($this->sys->phpCliMemoryLimit());
        $expected = $this->spec->get('PHP_CLI_MEMORY_LIMIT');
        if ($limit === '-1' || $limit === '') {
            $findings[] = GuardFinding::critical(
                'php-cli',
                'memory_limit CLI = -1 (потолка нет) — один artisan-процесс снова может съесть контейнер',
            );
        } elseif (strcasecmp($limit, $expected) !== 0) {
            $findings[] = GuardFinding::warning('php-cli', "memory_limit CLI = {$limit}, ожидалось {$expected}");
        }

        $pool = "/etc/php/{$this->spec->get('PHP_VERSION')}/fpm/pool.d/www.conf";
        $body = $this->sys->fileContents($pool);
        if ($body === null) {
            $findings[] = GuardFinding::warning('php-fpm', "{$pool} не читается");

            return $findings;
        }
        foreach (['pm.max_children' => 'FPM_MAX_CHILDREN', 'pm.max_requests' => 'FPM_MAX_REQUESTS'] as $directive => $key) {
            $want = $this->spec->get($key);
            $pattern = '/^\s*'.preg_quote($directive, '/').'\s*=\s*(\S+)/m';
            if (preg_match_all($pattern, $body, $m) === 0) {
                $findings[] = GuardFinding::warning('php-fpm', "{$directive} не задан в {$pool} (ожидалось {$want})");

                continue;
            }
            $effective = end($m[1]);
            if ($effective !== $want) {
                $findings[] = GuardFinding::warning('php-fpm', "{$directive} = {$effective}, ожидалось {$want}");
            }
        }

        return $findings;
    }

    /**
     * @return list<GuardFinding>
     */
    private function auditRequiredUnits(): array
    {
        $findings = [];

        foreach ($this->spec->csv('REQUIRED_ACTIVE_UNITS') as $unit) {
            if (! $this->sys->unitIsActive($unit)) {
                $findings[] = GuardFinding::critical('unit-active', "{$unit} не active");
            }
        }
        foreach ($this->spec->csv('REQUIRED_ACTIVE_TIMERS') as $timer) {
            if (! $this->sys->unitIsActive($timer)) {
                $findings[] = GuardFinding::warning(
                    'unit-active',
                    "{$timer} не active — истории памяти в следующий раз не будет",
                );
            }
        }

        return $findings;
    }

    /**
     * @return list<GuardFinding>
     */
    private function auditEarlyoomArgs(): array
    {
        $body = $this->sys->fileContents('/etc/default/earlyoom');
        if ($body === null) {
            return []; // Отсутствие файла уже поймано манифестом.
        }
        if (preg_match('/^\s*EARLYOOM_ARGS\s*=\s*(.*)$/m', $body, $m) !== 1) {
            return [GuardFinding::critical('earlyoom', 'EARLYOOM_ARGS не задан — earlyoom работает с настройками по умолчанию')];
        }

        $actual = trim(trim($m[1]), '"');
        $expectedTerm = $this->spec->get('EARLYOOM_TERM_PERCENT').','.$this->spec->get('EARLYOOM_KILL_PERCENT');
        $findings = [];
        if (! str_contains($actual, '-m '.$expectedTerm)) {
            $findings[] = GuardFinding::warning('earlyoom', "пороги не «-m {$expectedTerm}»: {$actual}");
        }
        if (! str_contains($actual, $this->spec->get('EARLYOOM_PREFER_RE'))) {
            $findings[] = GuardFinding::warning('earlyoom', '--prefer разошёлся с server_guards.conf: '.$actual);
        }

        return $findings;
    }

    /**
     * @return list<GuardFinding>
     */
    private function auditSwap(): array
    {
        $swap = $this->sys->swapTotalBytes();
        if ($swap === null || $swap > 0) {
            return [];
        }

        return [GuardFinding::info(
            'swap',
            'свопа нет: у ядра нет упругости, livelock физически возможен. Внутри LXC не заводится — только на хосте Proxmox (pct set <vmid> -swap 4096)',
        )];
    }

    private function normalize(string $text): string
    {
        return rtrim(str_replace("\r\n", "\n", $text), "\n");
    }
}
