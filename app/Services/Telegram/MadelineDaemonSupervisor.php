<?php

declare(strict_types=1);

namespace App\Services\Telegram;

/**
 * Держит демон MadelineProto в СВОЕЙ ресурсной группе и под потолком.
 *
 * Зачем это существует (H3121, 19-08-2026). Демон `MadelineProto worker`
 * рождается там, где его впервые попросили: до этого — внутри `schedule:run`,
 * то есть в cgroup `system.slice/cron.service`. Он отцепляется к PPID 1, но
 * cgroup наследуется на fork и сама собой не меняется — и демон остаётся жить
 * за счёт бюджета `MemoryHigh=2G`, который стоит на cron.service с 28-07-2026
 * (H1904). За 33 часа он вырос до 2.29 ГиБ RSS и 2 774 дескрипторов на один
 * файл `madelineproto.log`, упёрся в memory.high — и ядро затормозило ВСЮ
 * группу: авто-деплой (composer 3 с → 16 мин), `schedule:run` (таймаут 900 с
 * каждую минуту, планировщик стоял ~7 часов), оба сторожа. Потолок сработал
 * штатно; неверно было то, ЧЕЙ бюджет тратил демон.
 *
 * Поэтому демон поднимается юнитом `systema-madeline-daemon.service`: процесс,
 * который его порождает, сам живёт в отдельной cgroup со своими числами, и
 * демон наследует именно её. Этот класс — то, что юнит крутит внутри:
 *
 *  1. Демон в ЧУЖОЙ cgroup (значит, его успел холодно поднять cron) — гасим.
 *     Пока он жив, `open()` просто подключится к его сокету, и лечения не
 *     случится: подключение к чужому демону — это и есть болезнь.
 *  2. Демон превысил потолок (RSS / дескрипторы / возраст) — гасим. Ceiling
 *     здесь безопасен ровно потому, что демон теперь свой: перезапуск не
 *     задевает ни cron, ни supervisor. До H3121 такой таймер был бы вреден —
 *     см. «Fail =» в H3121.
 *  3. Своего живого демона нет — поднимаем через {@see MadelineClientFactory},
 *     то есть ИЗ ЭТОГО процесса, чтобы cgroup унаследовалась правильная.
 *
 * Второй дефект того же инцидента — унаследованный `flock` планировщика — сюда
 * НЕ относится и чинится там, где рождается: обёртка
 * `scripts/server_guards/sbin/systema-schedule-run.sh` закрывает fd 9 ребёнку
 * (`9>&-`). Ни один потолок и ни один kill освободить чужой fd не может.
 */
class MadelineDaemonSupervisor
{
    public const REASON_FOREIGN_CGROUP = 'foreign-cgroup';

    public const REASON_RSS = 'rss';

    public const REASON_FDS = 'fds';

    public const REASON_AGE = 'age';

    /**
     * @param  int  $maxRssKb  0 = без потолка
     * @param  int  $maxFds  0 = без потолка
     * @param  int  $maxAgeSeconds  0 = без потолка
     */
    public function __construct(
        private readonly DaemonProcessProbe $probe,
        private readonly MadelineClientFactory $factory,
        private readonly int $maxRssKb,
        private readonly int $maxFds,
        private readonly int $maxAgeSeconds,
    ) {}

    /**
     * Один заход надзора. Ничего не бросает: сторож, роняющий себя, хуже
     * отсутствующего (та же логика, что у heartbeat:ping).
     *
     * @return array{
     *     own_cgroup: string|null,
     *     seen: list<array{pid: int, cgroup: string|null, rss_kb: int|null, fds: int|null, age_s: int|null}>,
     *     killed: list<array{pid: int, reason: string, detail: string}>,
     *     spawned: bool,
     *     spawn_error: string|null,
     *     healthy: list<int>,
     * }
     */
    public function tick(): array
    {
        $ownCgroup = $this->probe->ownCgroup();
        $seen = [];
        $killed = [];
        $healthy = [];

        foreach ($this->daemonPids() as $pid) {
            $cgroup = $this->probe->cgroupOf($pid);
            $rss = $this->probe->rssKbOf($pid);
            $fds = $this->probe->fdCountOf($pid);
            $age = $this->probe->ageSecondsOf($pid);
            $seen[] = ['pid' => $pid, 'cgroup' => $cgroup, 'rss_kb' => $rss, 'fds' => $fds, 'age_s' => $age];

            $verdict = $this->verdict($ownCgroup, $cgroup, $rss, $fds, $age);
            if ($verdict === null) {
                $healthy[] = $pid;

                continue;
            }
            $this->reap($pid);
            $killed[] = ['pid' => $pid, 'reason' => $verdict[0], 'detail' => $verdict[1]];
        }

        $spawned = false;
        $spawnError = null;
        if ($healthy === []) {
            [$spawned, $spawnError] = $this->spawn();
        }

        return [
            'own_cgroup' => $ownCgroup,
            'seen' => $seen,
            'killed' => $killed,
            'spawned' => $spawned,
            'spawn_error' => $spawnError,
            'healthy' => $healthy,
        ];
    }

    /**
     * За что демона гасят, или null — годен.
     *
     * Порядок ветвей — порядок важности: чужая cgroup убивает соседей по
     * группе, потолки убивают только сам демон.
     *
     * @return array{0: string, 1: string}|null
     */
    private function verdict(?string $ownCgroup, ?string $cgroup, ?int $rss, ?int $fds, ?int $age): ?array
    {
        // Не видно ни своей группы, ни чужой (не Linux, нет /proc) — не наше
        // дело решать. Fail-open: демон остаётся жить.
        if ($ownCgroup !== null && $cgroup !== null && $cgroup !== $ownCgroup) {
            return [self::REASON_FOREIGN_CGROUP, "cgroup {$cgroup} ≠ {$ownCgroup} — демон тратит чужой бюджет памяти"];
        }
        if ($this->maxRssKb > 0 && $rss !== null && $rss > $this->maxRssKb) {
            return [self::REASON_RSS, 'RSS '.intdiv($rss, 1024).' МиБ > '.intdiv($this->maxRssKb, 1024).' МиБ'];
        }
        if ($this->maxFds > 0 && $fds !== null && $fds > $this->maxFds) {
            // Течь дескрипторов на madelineproto.log — vendor-дефект: их ~1 на
            // подключение клиента, то есть ~1440 в сутки при крон-минутке.
            // Починить в vendor нельзя, поэтому она ОГРАНИЧЕНА: перезапуск
            // демона обнуляет счёт и стоит ~секунду.
            return [self::REASON_FDS, "дескрипторов {$fds} > {$this->maxFds} (течь на madelineproto.log)"];
        }
        if ($this->maxAgeSeconds > 0 && $age !== null && $age > $this->maxAgeSeconds) {
            return [self::REASON_AGE, 'возраст '.intdiv($age, 3600).' ч > '.intdiv($this->maxAgeSeconds, 3600).' ч'];
        }

        return null;
    }

    /**
     * SIGTERM, и SIGKILL, если через $graceSeconds процесс ещё жив.
     *
     * Именно так, а не «TERM и надеяться»: 19-08-2026 зависший демон
     * игнорировал TERM 20 секунд — его shutdown-путь падает в
     * `Revolt\EventLoop … DriverSuspension`, что и записано в madelineproto.log.
     */
    private function reap(int $pid, int $graceSeconds = 5): void
    {
        $this->probe->signal($pid, 15);
        for ($i = 0; $i < $graceSeconds; $i++) {
            if (! $this->probe->isAlive($pid)) {
                return;
            }
            $this->probe->sleep(1);
        }
        $this->probe->signal($pid, 9);
    }

    /**
     * Поднять демон ИЗ ЭТОГО процесса — только так он унаследует нашу cgroup.
     *
     * Клиент сразу отпускаем: держать открытым IPC-клиента незачем, демон живёт
     * сам (PPID 1) и переживает наш заход. Нам важен только факт рождения в
     * правильной группе.
     *
     * @return array{0: bool, 1: string|null}
     */
    private function spawn(): array
    {
        if (! $this->factory->isConfigured()) {
            return [false, 'MadelineProto не настроен (нет api_id/api_hash/клиента) — поднимать нечего'];
        }

        try {
            $client = $this->factory->open();
            unset($client);

            return [true, null];
        } catch (\Throwable $e) {
            return [false, $e->getMessage()];
        }
    }

    /**
     * Пиды фоновых процессов ИМЕННО ЭТОЙ сессии.
     *
     * Фильтр по пути сессии, а не по слову «madeline» — причина та же, по
     * которой так устроен {@see MadelineSessionReaper}: 27-07-2026 фильтр по
     * слову заставил десять заходов убивать демонов друг друга по кругу.
     * Собственный пид исключаем: наша командная строка содержит путь сессии
     * не всегда, но исключение бесплатно и снимает целый класс самоубийств.
     *
     * @return list<int>
     */
    private function daemonPids(): array
    {
        $session = MadelineClientFactory::sessionPath();
        if ($session === '') {
            return [];
        }

        $self = function_exists('getmypid') ? (int) getmypid() : 0;

        return array_values(array_filter(
            $this->probe->pidsMatching($this->pgrepPattern($session)),
            static fn (int $pid): bool => $pid !== $self,
        ));
    }

    /**
     * Паттерн для `pgrep -f`: маркер демона, пробел, путь сессии.
     *
     * Путь сессии экранируется как литерал (pgrep трактует аргумент как ERE) —
     * то же правило, что в {@see MadelineSessionReaper::pgrepPattern()}. А вот
     * ОДНОГО пути мало, и это выяснилось на живом проде 19-08-2026, в первом же
     * заходе супервизора: строка разбора
     * `for p in $(pgrep -f "MadelineProto worker"); do cat /proc/$p/cgroup`
     * сама попадает в собственную выборку — путь сессии есть и в её командной
     * строке. Реаперу это безразлично (он бьёт SIGTERM под замком сессии), а
     * супервизор гасит всё, что живёт в ЧУЖОЙ cgroup, — и диагностическая
     * оболочка администратора всегда живёт в чужой. Без маркера человек,
     * посмотревший на демона, через минуту терял свой ssh.
     *
     * Маркеров два, и оба в этой же форме: воркер называет себя
     * `MadelineProto worker <session>` (cli_set_process_title), раннер —
     * `madeline-ipc <session> <startupId>`.
     */
    private function pgrepPattern(string $session): string
    {
        $literal = preg_replace('~([.\[\]()*+?^$|{}\\\\])~', '\\\\$1', $session) ?? $session;

        return '(MadelineProto worker|madeline-ipc) '.$literal;
    }
}
