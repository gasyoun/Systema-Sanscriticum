<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Telegram\DaemonProcessProbe;
use App\Services\Telegram\ProcDaemonProcessProbe;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\FakeDaemonProcessProbe;
use Tests\TestCase;

/**
 * H3121: юнит `systema-madeline-daemon.service` крутит именно эту команду.
 *
 * Проверяется то, чего unit-тест супервизора не видит: разводка в контейнере
 * (юнит стартует ровно потому, что `DaemonProcessProbe` кем-то разрешается),
 * чтение потолков из scripts/server_guards.conf и то, что каждый заход
 * оставляет в journal строку, по которой человека можно разбудить.
 *
 * Вывод читаем через Artisan::output(), а не expectsOutputToContain():
 * тот подменяет OutputStyle моком, и строки, на которые не зарегистрировано
 * ожидание, до буфера не доезжают — тест «не находил» текст, который команда
 * заведомо печатает.
 */
class TelegramSupportDaemonCommandTest extends TestCase
{
    private function runOnce(): string
    {
        $this->withoutMockingConsoleOutput();
        Artisan::call('telegram-support:daemon', ['--once' => true]);

        return Artisan::output();
    }

    public function test_the_container_resolves_a_real_probe(): void
    {
        // Без этой привязки юнит падал бы на старте с BindingResolutionException,
        // и systemd крутил бы рестарт по кругу.
        $this->assertInstanceOf(ProcDaemonProcessProbe::class, app(DaemonProcessProbe::class));
    }

    public function test_one_tick_reports_the_ceilings_it_actually_read(): void
    {
        // Потолки печатаются из server_guards.conf — единственного места, где
        // живут числа предохранителей. Тест заодно доказывает, что файл
        // читается: разъехавшись, эти числа врали бы молча.
        $probe = new FakeDaemonProcessProbe;
        $probe->add(4242);
        $this->app->instance(DaemonProcessProbe::class, $probe);

        $output = $this->runOnce();

        $this->assertStringContainsString('supervisor up:', $output);
        $this->assertStringContainsString('ceilings rss=700MB fds=2000 age=12h', $output);
        $this->assertStringContainsString('ok pid=4242', $output);
        $this->assertStringContainsString('/system.slice/systema-madeline-daemon.service', $output);
        $this->assertSame(0, $probe->slept, 'один заход не должен спать вовсе');
    }

    public function test_a_daemon_in_a_foreign_cgroup_is_reaped_loudly(): void
    {
        // Строка REAPED — это и есть ответ на вопрос «почему прод тормозил»,
        // которого 19-08-2026 не существовало нигде.
        $probe = new FakeDaemonProcessProbe;
        $probe->add(2557501, FakeDaemonProcessProbe::CRON_CGROUP);
        $this->app->instance(DaemonProcessProbe::class, $probe);

        $output = $this->runOnce();

        $this->assertStringContainsString('REAPED pid=2557501 reason=foreign-cgroup', $output);
        $this->assertStringContainsString('/system.slice/cron.service', $output);
    }
}
