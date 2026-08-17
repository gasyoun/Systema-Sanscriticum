<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\SyncTelegramSupport;
use App\Services\Telegram\MadelineSessionReaper;
use App\Services\Telegram\MadelineSyncPhase;
use App\Services\Telegram\MadelineSyncWatchdog;
use App\Support\ServerGuards\GuardSpec;
use Illuminate\Console\OutputStyle;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use ReflectionMethod;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Предохранители MTProto-синка, поставленные после инцидента 27.07.2026: заход
 * висел часами, замок планировщика протухал раньше, чем процесс умирал, и на
 * одной сессии накапливались параллельные экземпляры — до исчерпания файловых
 * дескрипторов (EMFILE) всего сервера.
 */
class MadelineSyncGuardsTest extends TestCase
{
    // Kernel::schedule() читает настройки из БД (MarketingSetting), поэтому
    // тесту планировщика нужна мигрированная схема.
    use RefreshDatabase;

    public function test_watchdog_is_an_honest_no_op_when_timeout_is_disabled(): void
    {
        $watchdog = new MadelineSyncWatchdog;

        // 0 — осознанное «без потолка»; вызывающий должен видеть, что не взвелось.
        $this->assertFalse($watchdog->arm(0));
        $this->assertFalse($watchdog->arm(-5));
    }

    /**
     * H2988: after a watchdog kill the next live minutes must not immediately
     * cold-start the same DC. Skip is SUCCESS so schedule:run releases its flock.
     */
    public function test_sync_skips_during_post_timeout_cooldown(): void
    {
        config(['services.telegram_support.sync_timeout_cooldown_seconds' => 600]);
        MadelineSyncPhase::armCooldown(600);

        $this->artisan('telegram-support:sync')
            ->expectsOutput('Telegram support sync: post_timeout_cooldown (waiting after watchdog kill).')
            ->assertExitCode(0);
    }

    public function test_timeout_cleanup_arms_post_timeout_cooldown_and_keeps_exit_75(): void
    {
        $this->assertSame(75, MadelineSyncWatchdog::EXIT_TIMED_OUT);

        config(['services.telegram_support.sync_timeout_cooldown_seconds' => 600]);
        $this->assertFalse(MadelineSyncPhase::cooldownActive());

        MadelineSyncPhase::mark('client_start');

        $this->invokeTimeoutCleanup();

        $this->assertTrue(MadelineSyncPhase::cooldownActive());
        $this->assertSame('client_start', MadelineSyncPhase::current());
    }

    public function test_zero_cooldown_does_not_skip_and_cleanup_does_not_arm(): void
    {
        config(['services.telegram_support.sync_timeout_cooldown_seconds' => 0]);

        $this->invokeTimeoutCleanup();

        $this->assertFalse(MadelineSyncPhase::cooldownActive());
    }

    private function invokeTimeoutCleanup(): void
    {
        $command = app(SyncTelegramSupport::class);
        $command->setLaravel($this->app);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput));

        $cleanup = new ReflectionMethod(SyncTelegramSupport::class, 'cleanUpAfterTimeout');
        $cleanup->setAccessible(true);
        $cleanup->invoke($command, app(MadelineSessionReaper::class), 120);
    }

    /**
     * Регрессия H1915 — ТОТ САМЫЙ отказ 28.07.2026: заход прожил 10 470 с при
     * потолке 120 с и завершился кодом 0.
     *
     * Форма важна и воспроизведена дословно: работа идёт в callback'ах Revolt, а
     * ошибки цикла проглатывает обработчик «залогировать и продолжить» (такой
     * ставит MadelineProto). На ней прежний watchdog, бросавший исключение, НЕ
     * убивал заход — исключение уходило в error handler, а одноразовый
     * pcntl_alarm был потрачен, и остаток захода шёл вообще без потолка.
     *
     * Отдельный процесс — потому что проверяется exit() процесса, а не исключение.
     */
    public function test_watchdog_kills_a_run_hung_inside_the_event_loop(): void
    {
        if (! (new MadelineSyncWatchdog)->isSupported()) {
            $this->markTestSkipped('Нет расширения pcntl (Windows / сборка без pcntl).');
        }

        $marker = storage_path('framework/testing/h1915-watchdog-cleanup.marker');
        File::ensureDirectoryExists(dirname($marker));
        File::delete($marker);

        $timeout = 2;
        $process = new Process(
            // Каталог именно в нижнем регистре — так он лежит в репозитории;
            // на Windows разница не видна, на Linux CI это было бы «файл не найден».
            [PHP_BINARY, base_path('tests/fixtures/watchdog_hung_run.php'), (string) $timeout, $marker],
            timeout: 60,
        );

        $started = microtime(true);
        $process->run();
        $elapsed = microtime(true) - $started;

        $this->assertSame(
            MadelineSyncWatchdog::EXIT_TIMED_OUT,
            $process->getExitCode(),
            'Заход, зависший внутри событийного цикла, обязан умереть кодом потолка. '
                ."stdout={$process->getOutput()} stderr={$process->getErrorOutput()}",
        );

        // Фикстура живёт 60 с без работающего потолка; щедрый запас, но на порядки
        // меньше того, что даёт отказ (на проде было 87x).
        $this->assertLessThan(20, $elapsed, 'Потолок сработал не вовремя.');

        // Уборка обязана отработать ДО exit(): иначе замок сессии (TTL 900 с)
        // провисел бы четверть часа после каждого таймаута.
        $this->assertFileExists($marker, 'Уборка перед завершением процесса не выполнилась.');
        $this->assertSame("cleanup:{$timeout}", File::get($marker));

        File::delete($marker);
    }

    public function test_reaper_clears_ipc_artifacts_but_keeps_credentials(): void
    {
        $session = storage_path('framework/testing/reaper-session.madeline');
        File::deleteDirectory($session);
        File::ensureDirectoryExists($session);

        File::put($session.DIRECTORY_SEPARATOR.'safe.php', 'credentials');
        File::put($session.DIRECTORY_SEPARATOR.'lightState.php', 'light');
        File::put($session.DIRECTORY_SEPARATOR.'ipcState.php', 'ipc state');
        File::put($session.DIRECTORY_SEPARATOR.'callback.ipc', '');
        File::put($session.DIRECTORY_SEPARATOR.'lock', '');
        File::put($session.DIRECTORY_SEPARATOR.'safe.php.lock', '');

        config(['services.telegram_support.session' => $session]);

        $reaper = new MadelineSessionReaper;
        $removed = $reaper->clearIpcArtifacts();
        $removed = array_merge($removed, $reaper->clearLockArtifacts());
        sort($removed);

        $this->assertSame(['callback.ipc', 'ipcState.php', 'lock', 'safe.php.lock'], $removed);

        // Учётные данные трогать нельзя: их удаление разлогинивает аккаунт и
        // требует повторного интерактивного входа с кодом.
        $this->assertFileExists($session.DIRECTORY_SEPARATOR.'safe.php');
        $this->assertFileExists($session.DIRECTORY_SEPARATOR.'lightState.php');

        File::deleteDirectory($session);
    }

    public function test_reaper_is_a_no_op_when_session_directory_is_absent(): void
    {
        config(['services.telegram_support.session' => storage_path('framework/testing/never-created.madeline')]);

        $this->assertSame([], (new MadelineSessionReaper)->clearIpcArtifacts());
    }

    /**
     * Ядро инварианта: замок планировщика обязан ПЕРЕЖИТЬ своего держателя —
     * иначе он протухнет на живом процессе и пустит второй экземпляр на ту же
     * MTProto-сессию (AUTH_RESTART; 27.07.2026 — десять синков и EMFILE).
     *
     * H1915 изменил, ОТ ЧЕГО он считается. Прежде — от watchdog-таймаута команды
     * (120 с → TTL 7 мин); 28.07.2026 заход прожил 10 470 с, то есть замок
     * протух за это время двадцать пять раз. Теперь граница берётся от
     * гарантированного потолка жизни процесса: обёртка снимает заход по
     * `timeout` на SCHEDULE_MAX_SECONDS, а straggler'а добивает reaper при
     * возрасте > 2x потолка — дольше не живёт никто, даже без pcntl.
     *
     * @dataProvider madelineSessionCommands
     */
    public function test_scheduler_lock_outlives_the_guaranteed_process_ceiling(string $command, string $timeoutConfig): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, $command));

        $this->assertNotNull($event, "{$command} пропал из планировщика.");

        $watchdogTimeout = (int) config($timeoutConfig);
        $this->assertGreaterThan(0, $watchdogTimeout);

        $hardCeiling = 2 * (int) config('schedule_guard.max_seconds');
        $this->assertGreaterThan(0, $hardCeiling);

        $ttlSeconds = $event->expiresAt * 60;

        $this->assertGreaterThan(
            $hardCeiling,
            $ttlSeconds,
            "TTL замка {$command} ({$ttlSeconds} с) не переживает жёсткий потолок жизни процесса ({$hardCeiling} с).",
        );

        // Прежний, более слабый инвариант обязан продолжать выполняться.
        $this->assertGreaterThan($watchdogTimeout, $ttlSeconds);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function madelineSessionCommands(): array
    {
        return [
            'support sync' => ['telegram-support:sync', 'services.telegram_support.sync_timeout_seconds'],
            'roster groups' => ['telegram-harvest:roster-groups', 'services.telegram_harvest.roster_timeout_seconds'],
        ];
    }

    /**
     * Число внешнего потолка живёт в scripts/server_guards.conf (его читают и
     * bash-применятель, и guards:verify). config/schedule_guard.php — копия для
     * расчёта TTL без дискового разбора каждую минуту; разъехавшись, копия дала
     * бы «вторую правду» о том же числе — ровно то, от чего GuardSpec написан.
     */
    public function test_external_ceiling_config_matches_server_guards_conf(): void
    {
        $spec = GuardSpec::fromFile(base_path('scripts/server_guards.conf'));

        $this->assertSame(
            (int) $spec->get('SCHEDULE_MAX_SECONDS'),
            (int) config('schedule_guard.max_seconds'),
            'config/schedule_guard.php разошёлся с scripts/server_guards.conf.',
        );
    }
}
