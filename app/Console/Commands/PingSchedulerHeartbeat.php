<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Throwable;

/**
 * Пульс планировщика (H1713): раз в несколько минут дёргает уникальный URL на
 * healthchecks.io. Пришёл пинг — тихо; не пришёл — внешний сервис сам поднимает
 * тревогу.
 *
 * Инверсия обычного мониторинга, и в этом весь смысл: сторож живёт снаружи и
 * срабатывает на МОЛЧАНИЕ, поэтому переживает смерть всего сервера. Проверка,
 * запущенная на самой машине, о собственной смерти сообщить не может — ровно
 * поэтому простой 24–26.07.2026 (issue #730) длился двое суток.
 *
 * Закрывает то, что внешний монитор доступности увидеть не в состоянии:
 * «сайт отвечает, а планировщик или Horizon мертвы» — снаружи неотличимо от
 * нормы, а внутри стоят рассылки, напоминания и выкладка.
 *
 * Fail-open во всех смыслах: без настроенного URL молчит, при любой ошибке
 * возвращает SUCCESS. Сторож, роняющий прогон планировщика, вреднее болезни —
 * упавшая команда в расписании тянет за собой соседние по слоту.
 */
class PingSchedulerHeartbeat extends Command
{
    protected $signature = 'heartbeat:ping {--dry : Показать вердикт и куда бы ушёл пинг, ничего не отправляя}';

    protected $description = 'Пульс планировщика на healthchecks.io — тревога поднимается по молчанию, а не по ошибке.';

    public function handle(): int
    {
        $url = trim((string) config('heartbeat.url', ''));

        $failures = $this->selfCheck();
        $healthy = $failures === [];

        // Печатаем вердикт всегда: команда полезна и как ручной ответ на
        // вопрос «жив ли Horizon прямо сейчас», а не только как молчаливый пульс.
        if ($healthy) {
            $this->info('Планировщик жив'.(config('heartbeat.check_horizon') ? ', Horizon работает.' : '.'));
        } else {
            $this->warn('Проблемы:');
            foreach ($failures as $failure) {
                $this->line('  • '.$failure);
            }
        }

        if ($url === '') {
            $this->comment('HEARTBEAT_PING_URL не задан — пульс выключен, пинг не отправлен.');

            return self::SUCCESS;
        }

        // healthchecks.io: <url> = «всё хорошо», <url>/fail = «поднимай тревогу
        // немедленно». Второе важнее, чем кажется: без него смерть Horizon
        // ждала бы истечения периода проверки, то есть лишние минуты простоя.
        $target = $healthy ? $url : rtrim($url, '/').'/fail';
        $body = $healthy ? 'ok' : implode('; ', $failures);

        if ($this->option('dry')) {
            $this->comment('--dry: пинг не отправлен. Ушёл бы на '.$target);

            return self::SUCCESS;
        }

        try {
            $response = Http::timeout((int) config('heartbeat.timeout', 10))
                ->withBody($body, 'text/plain')
                ->post($target);

            if ($response->successful()) {
                $this->info('Пинг доставлен: '.$target);
            } else {
                // Не роняем команду: пропущенный пинг сам по себе и есть сигнал,
                // healthchecks.io поднимет тревогу по истечении периода.
                $this->error('healthchecks.io ответил '.$response->status().' — пинг не засчитан.');
                Log::warning('Пульс планировщика: healthchecks.io вернул ошибку', [
                    'status' => $response->status(),
                ]);
            }
        } catch (Throwable $e) {
            $this->error('Пинг не ушёл: '.$e->getMessage());
            Log::warning('Пульс планировщика: пинг не отправлен', ['error' => $e->getMessage()]);
        }

        return self::SUCCESS;
    }

    /**
     * Что мы вообще можем проверить, находясь внутри.
     *
     * Факт «планировщик жив» отдельной проверки не требует: если команда
     * выполняется, значит `schedule:run` работает. Проверять тут имеет смысл
     * только то, что может умереть НЕЗАВИСИМО от планировщика.
     *
     * @return list<string> список поломок; пустой список = всё в порядке
     */
    private function selfCheck(): array
    {
        if (! config('heartbeat.check_horizon', true)) {
            return [];
        }

        if (! interface_exists(MasterSupervisorRepository::class)) {
            return [];
        }

        try {
            $masters = app(MasterSupervisorRepository::class)->all();
        } catch (Throwable $e) {
            // Обычно это лежащий Redis — то есть очереди всё равно мертвы,
            // так что тревога здесь по делу.
            return ['статус Horizon не читается ('.$e->getMessage().')'];
        }

        if ($masters === []) {
            // Horizon держит записи о себе в Redis с TTL, поэтому пустой список
            // означает «мастер-процесс не отчитывается», а не «ещё не успел».
            return ['Horizon не запущен — очереди стоят'];
        }

        $paused = [];
        foreach ($masters as $master) {
            $status = is_array($master) ? ($master['status'] ?? null) : ($master->status ?? null);
            if ($status !== 'running') {
                $name = is_array($master) ? ($master['name'] ?? '?') : ($master->name ?? '?');
                $paused[] = $name.' ('.($status ?? 'статус неизвестен').')';
            }
        }

        return $paused === [] ? [] : ['Horizon не в рабочем режиме: '.implode(', ', $paused)];
    }
}
