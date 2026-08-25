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
        return $this->applyWaivers(array_merge(
            $this->auditManagedFiles(),
            $this->auditCrontab(),
            $this->auditAutoDeploy(),
            $this->auditUnitLimits(),
            $this->auditSingleMemoryDefinition(),
            $this->auditPhp(),
            $this->auditRequiredUnits(),
            $this->auditEarlyoomArgs(),
            $this->auditSwap(),
            $this->auditCronCgroupPressure(),
            $this->auditSchedulerStamp(),
            $this->auditTmpfsCap(),
            $this->auditFailedUnits(),
            $this->auditBackupFreshness(),
        ));
    }

    /**
     * Waiver: осознанно терпимые находки (2026-08-25).
     *
     * Зачем. tmpfs-cap на .92 критичен с 19-08-2026 и не может перестать им
     * быть изнутри гостя: монтирование сделано на хосте Proxmox (чужой uid=),
     * потолок ставит только человек со стороны хоста. Каждый деплой печатал
     * красный блок «предохранители расходятся» (exit 75, #1143), probe каждые
     * сутки напоминал в Telegram — сигнал, который нельзя выполнить, приучает
     * его игнорировать («мальчик и волк»), а рядом с ним тонет настоящий drift.
     *
     * Как работает. Имена из GUARD_WAIVERS до даты GUARD_WAIVERS_EXPIRES
     * понижаются до info: находка остаётся видимой (ℹ в verify, комментарий в
     * probe), но не блокирует verify и не будит Telegram.
     *
     * Три правила против гниения:
     *   • fail-closed — даты нет / не YYYY-MM-DD / истекла: waiver НЕ
     *     действует, находка остаётся как была (сама и есть тревога);
     *   • misconfig виден: GUARD_WAIVERS задан, а EXPIRES сломан — отдельный
     *     warning поверх нетронутых находок;
     *   • имя, которому нечего вайвить (предохранитель снова здоров или
     *     опечатка), даёт info «убери из GUARD_WAIVERS» — конфиг не должен
     *     молчать о том, что стал мёртвым текстом.
     *
     * @param  list<GuardFinding>  $findings
     * @return list<GuardFinding>
     */
    private function applyWaivers(array $findings): array
    {
        if (! $this->spec->has('GUARD_WAIVERS')) {
            return $findings;
        }

        $names = $this->spec->csv('GUARD_WAIVERS');
        if ($names === []) {
            return $findings;
        }

        $expiresRaw = $this->spec->has('GUARD_WAIVERS_EXPIRES')
            ? trim($this->spec->get('GUARD_WAIVERS_EXPIRES'))
            : '';
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiresRaw) !== 1) {
            return [...$findings, GuardFinding::warning(
                'waiver',
                'GUARD_WAIVERS задан ('.implode(', ', $names).'), но GUARD_WAIVERS_EXPIRES '
                .'отсутствует или не дата YYYY-MM-DD — waiver НЕ действует',
            )];
        }

        $expires = strtotime($expiresRaw.' 23:59:59');
        if ($expires === false || time() > $expires) {
            return $findings; // истёк: находки остаются как были — это и есть тревога.
        }

        $waived = [];
        foreach ($findings as $i => $finding) {
            if (! in_array($finding->guard, $names, true) || $finding->severity === GuardFinding::INFO) {
                continue;
            }
            $findings[$i] = new GuardFinding(
                GuardFinding::INFO,
                $finding->guard,
                $finding->message.' [waiver до '.$expiresRaw.']',
            );
            $waived[] = $finding->guard;
        }

        foreach (array_values(array_diff($names, array_unique($waived))) as $stale) {
            $findings[] = GuardFinding::info(
                'waiver',
                "waiver не понадобился: {$stale} нет среди находок — убрать из GUARD_WAIVERS (server_guards.conf)",
            );
        }

        return $findings;
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
     * Авто-деплой (H1933): root-крон каждые 30 минут + предохранитель.
     *
     * Предохранитель storage/auto_deploy.disabled ставится обёрткой после
     * ПРОВАЛЕННОГО деплоя или проваленной пост-деплойной проверки здоровья.
     * Severity (H2066 + H2104): метки [rolled-back], [blocked-preflight],
     * [timeout-alive] — warning (сайт жив / HEAD не сдвинулся / timeout при
     * smoke 200); без метки — critical (Telegram). Пропажа cron-строки —
     * warning. Tracked dirty (не public/docs/*.pdf) — warning до следующего
     * слота деплоя, чтобы не ждать breaker.
     *
     * @return list<GuardFinding>
     */
    private function auditAutoDeploy(): array
    {
        $findings = [];

        $breaker = rtrim($this->spec->get('APP_DIR'), '/').'/storage/auto_deploy.disabled';
        if ($this->sys->fileExists($breaker)) {
            $reason = trim((string) $this->sys->fileContents($breaker));
            $lastLine = $reason === '' ? 'без причины' : (array_slice(preg_split('/\r\n|\n|\r/', $reason) ?: [''], -1)[0] ?: 'без причины');
            $message = "авто-деплой остановлен предохранителем ({$lastLine}) — разобраться и удалить {$breaker}";
            $soft = str_contains($lastLine, '[rolled-back]')
                || str_contains($lastLine, '[blocked-preflight]')
                || str_contains($lastLine, '[blocked-dirty]')
                || str_contains($lastLine, '[timeout-alive]');
            $findings[] = $soft
                ? GuardFinding::warning('auto-deploy', $message)
                : GuardFinding::critical('auto-deploy', $message);
        }

        $crontab = $this->sys->crontabFor('root');
        $line = null;
        foreach (preg_split('/\r\n|\n|\r/', (string) $crontab) ?: [] as $raw) {
            $trimmed = trim($raw);
            if ($trimmed !== '' && ! str_starts_with($trimmed, '#') && str_contains($trimmed, 'systema-auto-deploy-run.sh')) {
                $line = $trimmed;
                break;
            }
        }
        if ($line === null) {
            $findings[] = GuardFinding::warning(
                'auto-deploy',
                'в crontab root нет строки systema-auto-deploy-run.sh — авто-деплой молча не работает',
            );
        } else {
            $schedule = $this->spec->get('AUTO_DEPLOY_SCHEDULE');
            if (! str_starts_with($line, $schedule)) {
                $findings[] = GuardFinding::warning(
                    'auto-deploy',
                    "авто-деплой: расписание не «{$schedule}» — строка «{$line}»",
                );
            }
        }

        $appDir = rtrim($this->spec->get('APP_DIR'), '/');
        $dirty = $this->sys->trackedDirtyPaths($appDir);
        if ($dirty !== null && $dirty !== []) {
            $allowedRe = '#^public/docs/[^/]+\.pdf$#';
            $blocking = array_values(array_filter(
                $dirty,
                static fn (string $path): bool => preg_match($allowedRe, $path) !== 1,
            ));
            if ($blocking !== []) {
                $sample = array_slice($blocking, 0, 5);
                $more = count($blocking) > 5 ? ' …+'.(count($blocking) - 5) : '';
                $findings[] = GuardFinding::warning(
                    'auto-deploy',
                    'tracked dirty на проде (deploy.sh/auto-deploy остановятся): '
                    .implode(', ', $sample).$more
                    .' — код только через main; git checkout -- <file> или stash',
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

        // H3121: у демона MadelineProto обязан быть СВОЙ потолок. Без него он
        // снова поедет на чужом бюджете — том, чей cgroup ему достанется первым.
        if ($this->spec->has('MADELINE_MEMORY_HIGH')) {
            $expectations[] = ['systema-madeline-daemon', 'MemoryHigh', (string) $this->spec->bytes('MADELINE_MEMORY_HIGH'), GuardFinding::CRITICAL];
            $expectations[] = ['systema-madeline-daemon', 'MemoryMax', (string) $this->spec->bytes('MADELINE_MEMORY_MAX'), GuardFinding::CRITICAL];
            $expectations[] = ['systema-madeline-daemon', 'TasksMax', $this->spec->get('MADELINE_TASKS_MAX'), GuardFinding::WARNING];
            $expectations[] = ['systema-madeline-daemon', 'OOMPolicy', 'kill', GuardFinding::WARNING];
        }

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

    /**
     * Группа cron.service ЖИВЁТ над своим memory.high (H3121).
     *
     * Чего эта проверка стоит. 19-08-2026 прод стоял семь часов при
     * MemAvailable 8.5 ГиБ: хостовой памяти было сколько угодно, кончился
     * бюджет ОДНОЙ cgroup, и ядро тормозило каждый процесс в ней —
     * авто-деплой, планировщик, обоих сторожей. Все существовавшие проверки
     * (health_check, memwatch, earlyoom, cabinet:probe) смотрят на хост и
     * структурно не могли этого увидеть. Смотреть надо на бюджет группы.
     *
     * Fail-open: нет cgroup v2, не читается memory.current, у cron нет потолка
     * — молчим. Проверка обязана быть бесплатной на любой машине, включая CI.
     *
     * @return list<GuardFinding>
     */
    private function auditCronCgroupPressure(): array
    {
        $current = $this->sys->fileContents('/sys/fs/cgroup/system.slice/cron.service/memory.current');
        if ($current === null || preg_match('/^\d+$/', trim($current)) !== 1) {
            return [];
        }
        $high = $this->sys->unitProperty('cron', 'MemoryHigh');
        if ($high === null || preg_match('/^\d+$/', trim($high)) !== 1 || (int) $high === 0) {
            return [];
        }

        $cur = (int) trim($current);
        $lim = (int) trim($high);
        $curMib = intdiv($cur, 1024 ** 2);
        $limMib = intdiv($lim, 1024 ** 2);

        if ($cur >= $lim) {
            return [GuardFinding::critical(
                'cgroup',
                "cron.service занял {$curMib} МиБ при memory.high {$limMib} МиБ — ядро ТОРМОЗИТ всю группу: "
                .'планировщик, сторожа и авто-деплой идут в разы дольше. Искать долгоживущий процесс в этой группе '
                .'(`systemd-cgls /system.slice/cron.service`), а НЕ поднимать потолок',
            )];
        }
        if ($cur * 5 >= $lim * 4) { // ≥80 %
            return [GuardFinding::warning(
                'cgroup',
                "cron.service занял {$curMib} МиБ из {$limMib} МиБ (≥80 %) — до троттлинга близко",
            )];
        }

        return [];
    }

    /**
     * Планировщик реально ДОХОДИЛ до конца в последние N минут (H3121).
     *
     * Отметку пишет systema-schedule-run.sh только после завершившегося
     * прогона; пропуск по замку её не трогает. Это единственный сигнал,
     * отличающий «крон на месте, файлы на месте» от «каждую минуту SKIP уже
     * семь часов» — ровно то состояние, которое 19-08-2026 не заметил никто.
     *
     * @return list<GuardFinding>
     */
    private function auditSchedulerStamp(): array
    {
        if (! $this->spec->has('SCHEDULER_STAMP_MAX_MINUTES')) {
            return [];
        }

        $path = rtrim($this->spec->get('APP_DIR'), '/').'/storage/framework/schedule-run.stamp';
        $raw = $this->sys->fileContents($path);
        if ($raw === null || preg_match('/^\d+$/', trim($raw)) !== 1) {
            // Отметки ещё нет (свежая установка, обёртка ни разу не доработала
            // до конца). Info, а не тревога: на первом же успешном прогоне она
            // появится сама, а падать из-за её отсутствия — ложный сигнал.
            return [GuardFinding::info('scheduler-stamp', 'отметки завершённого schedule:run ещё нет — появится после первого прогона')];
        }

        $ageMin = intdiv(max(0, time() - (int) trim($raw)), 60);
        $maxMin = (int) $this->spec->get('SCHEDULER_STAMP_MAX_MINUTES');
        if ($maxMin > 0 && $ageMin > $maxMin) {
            return [GuardFinding::critical(
                'scheduler-stamp',
                "последний ЗАВЕРШЁННЫЙ schedule:run был {$ageMin} мин назад (порог {$maxMin}) — планировщик стоит: "
                .'смотреть storage/logs/schedule.log на SKIP-шторм и кто держит storage/framework/schedule-run.lock '
                .'(`ls -l /proc/*/fd | grep schedule-run.lock`)',
            )];
        }

        return [];
    }

    /**
     * У /tmp есть ЯВНЫЙ потолок (H-1, 19-08-2026).
     *
     * Чего эта проверка стоит. Стоковый tmp.mount просит size=50%, и внутри LXC
     * ядро считает эти проценты от памяти ХОСТА: 126 ГиБ при 16 ГиБ у гостя.
     * Запись в /tmp — это заявка на RAM, и ни один существовавший
     * предохранитель её не видел: earlyoom смотрит на процессы, memwatch — на
     * итог, cgroup-проверка — на бюджет крона. 7.6 ГиБ брошенного ASR-скретча
     * держали своп на 5.5 ГиБ из 8.
     *
     * Fail-open: нет /proc/mounts, нет отдельного /tmp, /tmp не tmpfs — молчим.
     * Проверка обязана быть бесплатной и на dev-машине, и в CI.
     *
     * @return list<GuardFinding>
     */
    private function auditTmpfsCap(): array
    {
        if (! $this->spec->has('TMP_TMPFS_SIZE')) {
            return [];
        }
        $mounts = $this->sys->fileContents('/proc/mounts');
        if ($mounts === null) {
            return [];
        }

        $options = null;
        foreach (preg_split('/\r\n|\n|\r/', $mounts) ?: [] as $line) {
            $fields = preg_split('/\s+/', trim($line)) ?: [];
            if (count($fields) < 4 || $fields[1] !== '/tmp') {
                continue;
            }
            if ($fields[2] !== 'tmpfs') {
                return []; // /tmp на диске — это не заявка на память.
            }
            $options = $fields[3];
        }
        if ($options === null) {
            return []; // /tmp не отдельная точка монтирования — капать нечего.
        }

        $expected = $this->spec->bytes('TMP_TMPFS_SIZE');
        if (preg_match('/(?:^|,)size=(\d+)([kKmMgG]?)(?:,|$)/', $options, $m) !== 1) {
            // Кто монтировал — решает, кому этот потолок ставить. uid= с
            // ненулевым значением означает tmpfs, созданный ВНЕ нашего
            // пространства имён (idmap LXC: хост монтирует с uid=100000).
            // Изнутри такой mount не перемонтировать вовсе — ядро заново
            // разбирает сохранённый uid и падает на «Invalid uid '100000'»,
            // и ни drop-in к tmp.mount, ни строка в /etc/fstab этого не
            // меняют: обе поверхности принадлежат systemd контейнера, а
            // монтирует не он. Замерено на .92 19-08-2026 (H3181).
            $foreign = preg_match('/(?:^|,)uid=([1-9]\d*)(?:,|$)/', $options) === 1;

            return [GuardFinding::critical(
                'tmpfs-cap',
                '/tmp смонтирован tmpfs БЕЗ явного size= — это потолок в половину памяти ХОСТА, '
                .'то есть заявка на RAM без ограничения (19-08-2026: 7.6 ГиБ скретча съели своп). '
                .($foreign
                    ? 'Монтировал НЕ контейнер (в опциях чужой uid=): изнутри не чинится — '
                      .'потолок ставится на стороне хоста Proxmox, это задача Артёма (P5 плана '
                      .'PLAN_SYSTEMA_SERVER_UPTIME_GUARDRAILS_2026H2.md). '
                      .'scripts/server_guards_apply.sh здесь бессилен, звать его незачем'
                    : 'Вернуть: scripts/server_guards_apply.sh'),
            )];
        }

        $actual = (int) $m[1] * match (strtolower($m[2])) {
            'k' => 1024,
            'm' => 1024 ** 2,
            'g' => 1024 ** 3,
            default => 1,
        };
        if ($actual > $expected) {
            // Потолок есть, но выше нашего — кто-то поднял его сознательно
            // (риск R1: законному конвейеру не хватило места). Сказать об этом
            // надо, валить машину — нет.
            return [GuardFinding::warning(
                'tmpfs-cap',
                '/tmp: size='.intdiv($actual, 1024 ** 2).' МиБ, в server_guards.conf '
                .intdiv($expected, 1024 ** 2).' МиБ — потолок подняли мимо репозитория',
            )];
        }

        return [];
    }

    /**
     * Ни один юнит не лежит в failed (H-2, 19-08-2026).
     *
     * samudra-health-monitor.service падал каждые 15 минут с 14-08 по 19-08:
     * `/opt/samudra/logs` принадлежал root, а юнит ходит от samudra. Монитор
     * публичного поиска был мёртв пять суток, и сказать об этом было некому —
     * `systemctl --failed` не смотрел никто.
     *
     * Warning, а не critical, сознательно: упавший юнит — это мёртвый сторож, а
     * не обязательно мёртвый сайт, и разделение мягкого и жёсткого в
     * docs/SERVER_SOFT_ALERT_PLAYBOOK.md существует ровно затем, чтобы
     * «что-то не так» не читалось как «кабинет лежит».
     *
     * @return list<GuardFinding>
     */
    private function auditFailedUnits(): array
    {
        if (! $this->spec->has('FAILED_UNITS_ALLOWLIST')) {
            return [];
        }
        $failed = $this->sys->failedUnits();
        if ($failed === null) {
            return [];
        }

        $allowed = $this->spec->csv('FAILED_UNITS_ALLOWLIST');
        $findings = [];
        foreach ($failed as $unit) {
            if (in_array($unit, $allowed, true)) {
                continue;
            }
            $findings[] = GuardFinding::warning(
                'failed-units',
                "{$unit} в состоянии failed — `systemctl status {$unit}` и `journalctl -u {$unit} -n 50`. "
                .'Осознанно терпимый провал вносить в FAILED_UNITS_ALLOWLIST с причиной, а не удалять проверку',
            );
        }

        return $findings;
    }

    /**
     * У каждого назначения бэкапа есть СВЕЖИЙ и ПРАВДОПОДОБНЫЙ архив (H-3).
     *
     * Замер 19-08-2026, ради которого проверка и написана: `backup:monitor`
     * называл yandex_disk ЗДОРОВЫМ, а лежали там два обрезка по 11.7 МиБ —
     * остатки оборвавшихся загрузок 1.4-ГиБ архива (HTTP 413, затем «Empty
     * reply from server»). Возраст сходился, содержимого не было. Поэтому
     * проверок здесь две: возраст И размер. Возраст без размера — зелёная
     * лампочка над пустым сейфом.
     *
     * Off-site строже локального: локальная копия делит судьбу с контейнером,
     * который она защищает.
     *
     * @return list<GuardFinding>
     */
    private function auditBackupFreshness(): array
    {
        if (! $this->spec->has('BACKUP_MAX_AGE_DAYS')) {
            return [];
        }
        $destinations = $this->sys->backupDestinations();
        if ($destinations === null) {
            return [];
        }

        $maxAgeDays = (int) $this->spec->get('BACKUP_MAX_AGE_DAYS');
        $minBytes = $this->spec->has('BACKUP_MIN_ARCHIVE_MB')
            ? (int) $this->spec->get('BACKUP_MIN_ARCHIVE_MB') * 1024 ** 2
            : 0;
        $offsite = $this->spec->has('BACKUP_OFFSITE_DISKS') ? $this->spec->csv('BACKUP_OFFSITE_DISKS') : [];
        $requireOffsite = $this->spec->has('BACKUP_REQUIRE_OFFSITE')
            && $this->spec->get('BACKUP_REQUIRE_OFFSITE') === '1';

        $findings = [];
        $liveOffsite = 0;

        foreach ($destinations as $row) {
            $disk = $row['disk'];
            $isOffsite = in_array($disk, $offsite, true);
            $severity = $isOffsite ? GuardFinding::CRITICAL : GuardFinding::WARNING;
            $where = $isOffsite ? 'off-site' : 'локальный';

            if (! $row['reachable']) {
                $findings[] = new GuardFinding($severity, 'backup-fresh', "{$where} диск {$disk} недостижим — бэкап туда не доедет");

                continue;
            }
            if ($row['newestAt'] === null) {
                $findings[] = new GuardFinding($severity, 'backup-fresh', "на {$where} диске {$disk} нет ни одного архива");

                continue;
            }

            $ageDays = intdiv(max(0, time() - $row['newestAt']), 86400);
            $fresh = $maxAgeDays <= 0 || $ageDays <= $maxAgeDays;
            if (! $fresh) {
                $findings[] = new GuardFinding(
                    $severity,
                    'backup-fresh',
                    "новейший архив на {$where} диске {$disk} старше {$maxAgeDays} суток ({$ageDays}) — "
                    .'смотреть storage/logs на провал backup:run',
                );
            }

            $bytes = $row['newestBytes'];
            if ($minBytes > 0 && $bytes !== null && $bytes < $minBytes) {
                $findings[] = new GuardFinding(
                    $severity,
                    'backup-fresh',
                    "новейший архив на {$where} диске {$disk} — ".intdiv($bytes, 1024 ** 2).' МиБ при пороге '
                    .intdiv($minBytes, 1024 ** 2).' МиБ: это почти наверняка ОБРЕЗОК оборвавшейся загрузки, '
                    .'а не резервная копия. Дата у такого обрезка свежая, и backup:monitor называет его здоровым',
                );
            }

            if ($isOffsite && $fresh && ($minBytes === 0 || $bytes === null || $bytes >= $minBytes)) {
                $liveOffsite++;
            }
        }

        if ($requireOffsite && $liveOffsite === 0) {
            $findings[] = GuardFinding::critical(
                'backup-fresh',
                'нет ни одного живого off-site назначения — единственная копия делит судьбу с контейнером, который защищает',
            );
        }

        return $findings;
    }

    private function normalize(string $text): string
    {
        return rtrim(str_replace("\r\n", "\n", $text), "\n");
    }
}
