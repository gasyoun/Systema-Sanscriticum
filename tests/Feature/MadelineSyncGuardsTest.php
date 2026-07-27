<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\MadelineSyncTimedOut;
use App\Services\Telegram\MadelineSessionReaper;
use App\Services\Telegram\MadelineSyncWatchdog;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
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

    public function test_watchdog_interrupts_a_hung_run(): void
    {
        $watchdog = new MadelineSyncWatchdog;

        if (! $watchdog->isSupported()) {
            $this->markTestSkipped('Нет расширения pcntl (Windows / сборка без pcntl).');
        }

        $this->assertTrue($watchdog->arm(1));

        try {
            $this->expectException(MadelineSyncTimedOut::class);

            // Имитация зависшего захода: без watchdog'а этот цикл шёл бы 10 с,
            // на проде — часами.
            $deadline = microtime(true) + 10;
            while (microtime(true) < $deadline) {
                usleep(50_000);
            }
        } finally {
            $watchdog->disarm();
        }
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

        config(['services.telegram_support.session' => $session]);

        $removed = (new MadelineSessionReaper)->clearIpcArtifacts();
        sort($removed);

        $this->assertSame(['callback.ipc', 'ipcState.php'], $removed);

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

    public function test_scheduler_lock_outlives_the_watchdog_timeout(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'telegram-support:sync'));

        $this->assertNotNull($event, 'telegram-support:sync пропал из планировщика.');

        $timeout = (int) config('services.telegram_support.sync_timeout_seconds');
        $this->assertGreaterThan(0, $timeout);

        // Ядро инварианта: заход умирает по своему таймауту РАНЬШЕ, чем замок
        // планировщика протухнет и пустит второй экземпляр на ту же сессию.
        $this->assertGreaterThan($timeout, $event->expiresAt * 60);
    }
}
