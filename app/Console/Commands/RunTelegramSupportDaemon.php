<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Telegram\DaemonProcessProbe;
use App\Services\Telegram\MadelineClientFactory;
use App\Services\Telegram\MadelineDaemonSupervisor;
use App\Support\ServerGuards\GuardSpec;
use Illuminate\Console\Command;
use Throwable;

/**
 * Главный процесс юнита `systema-madeline-daemon.service` (H3121).
 *
 * Юнит существует ради одной вещи: у демона MadelineProto должна быть СВОЯ
 * ресурсная группа. Демон наследует cgroup того, кто его породил, поэтому
 * порождать его обязан долгоживущий процесс внутри собственного юнита, а не
 * `schedule:run` внутри `cron.service`. Разбор — {@see MadelineDaemonSupervisor}
 * и docs/server-resource-guards.md §9.
 *
 * Процесс намеренно тупой: каждые N секунд один заход надзора и снова спать.
 * Вся судьба — на systemd (`Restart=always`, `MemoryMax`, `OOMPolicy=kill`);
 * своей логики перезапуска здесь нет и быть не должно — это была бы вторая
 * правда о том, кто владеет демоном.
 *
 * Числа потолков живут в scripts/server_guards.conf, вместе с остальными
 * числами предохранителей. Второго места для них нет сознательно (см. шапку
 * того файла).
 */
class RunTelegramSupportDaemon extends Command
{
    protected $signature = 'telegram-support:daemon
        {--once : Один заход надзора и выход (для проверок и тестов)}
        {--interval= : Секунд между заходами (по умолчанию MADELINE_DAEMON_CHECK_SECONDS)}
        {--max-ticks=0 : Остановиться после N заходов; 0 — бесконечно}';

    protected $description = 'Держит демон MadelineProto в собственной cgroup и под потолком RSS/дескрипторов/возраста (H3121)';

    /** Значения по умолчанию, если server_guards.conf недоступен (dev, CI). */
    private const FALLBACK = [
        'MADELINE_DAEMON_MAX_RSS_MB' => 700,
        'MADELINE_DAEMON_MAX_FDS' => 2000,
        'MADELINE_DAEMON_MAX_AGE_HOURS' => 12,
        'MADELINE_DAEMON_CHECK_SECONDS' => 60,
    ];

    public function handle(DaemonProcessProbe $probe, MadelineClientFactory $factory): int
    {
        $spec = $this->spec();

        $supervisor = new MadelineDaemonSupervisor(
            $probe,
            $factory,
            maxRssKb: $this->number($spec, 'MADELINE_DAEMON_MAX_RSS_MB') * 1024,
            maxFds: $this->number($spec, 'MADELINE_DAEMON_MAX_FDS'),
            maxAgeSeconds: $this->number($spec, 'MADELINE_DAEMON_MAX_AGE_HOURS') * 3600,
        );

        $interval = (int) ($this->option('interval') ?: $this->number($spec, 'MADELINE_DAEMON_CHECK_SECONDS'));
        $interval = max(5, $interval);
        $maxTicks = $this->option('once') ? 1 : (int) $this->option('max-ticks');

        // Без этого `systemctl restart` ждал полный TimeoutStopSec=30s и добивал
        // нас SIGKILL: процесс спал в sleep(60) и сигнала не видел, а systemd
        // писал «Failed with result 'timeout'». Плата не косметическая —
        // KillMode=control-group сносит вместе с нами и демона, и всё это время
        // демона нет, а значит очередной cron-заход поднимет свой, под кроном.
        // Тридцать секунд такого окна против одной — вот и вся разница.
        $stopping = false;
        if (defined('SIGTERM')) {
            $this->trap([SIGTERM, SIGINT], function () use (&$stopping): void {
                $stopping = true;
            });
        }

        $this->stamp('supervisor up: interval='.$interval.'s, ceilings rss='
            .$this->number($spec, 'MADELINE_DAEMON_MAX_RSS_MB').'MB fds='
            .$this->number($spec, 'MADELINE_DAEMON_MAX_FDS').' age='
            .$this->number($spec, 'MADELINE_DAEMON_MAX_AGE_HOURS').'h');

        for ($tick = 1; $maxTicks === 0 || $tick <= $maxTicks; $tick++) {
            $this->report($supervisor->tick());

            if ($maxTicks !== 0 && $tick >= $maxTicks) {
                break;
            }
            // Спим посекундно, а не одним sleep($interval): иначе сигнал ждал бы
            // конца интервала, и вся ловушка выше была бы бутафорией.
            for ($slept = 0; $slept < $interval && ! $stopping; $slept++) {
                $probe->sleep(1);
            }
            if ($stopping) {
                $this->stamp('SIGTERM — выхожу; демон остаётся на попечении systemd');
                break;
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function report(array $result): void
    {
        foreach ($result['killed'] as $kill) {
            // Громко и в journal: это ровно та строка, которой 19-08-2026 не
            // существовало, и разбор занял 40 минут вместо одной.
            $this->stamp("REAPED pid={$kill['pid']} reason={$kill['reason']} — {$kill['detail']}");
        }
        if ($result['spawned']) {
            $this->stamp('SPAWNED: демон поднят из cgroup '.($result['own_cgroup'] ?? '?'));
        }
        if ($result['spawn_error'] !== null) {
            $this->stamp('SPAWN FAILED: '.$result['spawn_error']);
        }
        foreach ($result['seen'] as $proc) {
            if (! in_array($proc['pid'], $result['healthy'], true)) {
                continue;
            }
            $this->stamp(sprintf(
                'ok pid=%d rss=%sMB fds=%s age=%sh cgroup=%s',
                $proc['pid'],
                $proc['rss_kb'] === null ? '?' : (string) intdiv($proc['rss_kb'], 1024),
                $proc['fds'] === null ? '?' : (string) $proc['fds'],
                $proc['age_s'] === null ? '?' : (string) intdiv($proc['age_s'], 3600),
                $proc['cgroup'] ?? '?',
            ));
        }
    }

    private function stamp(string $message): void
    {
        $this->line(gmdate('Y-m-d\TH:i:s\Z').' '.$message);
    }

    /**
     * server_guards.conf, если он читается. Недоступен (dev, CI, чужая
     * машина) — работаем на дефолтах и говорим об этом вслух, а не падаем:
     * команда обязана быть запускаемой руками где угодно.
     */
    private function spec(): ?GuardSpec
    {
        try {
            return GuardSpec::fromFile((string) config('server_guards.spec_path'));
        } catch (Throwable $e) {
            $this->stamp('server_guards.conf не прочитан ('.$e->getMessage().') — потолки по умолчанию');

            return null;
        }
    }

    private function number(?GuardSpec $spec, string $key): int
    {
        if ($spec !== null && $spec->has($key)) {
            return (int) $spec->get($key);
        }

        return self::FALLBACK[$key];
    }
}
