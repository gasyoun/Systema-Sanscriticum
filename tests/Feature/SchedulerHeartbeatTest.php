<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Tests\TestCase;

/**
 * Пульс планировщика (H1713).
 *
 * Главное, что здесь закрывается тестами, — НЕ «пинг уходит», а два свойства,
 * ломающиеся молча и потому опасные:
 *
 *   1) fail-open: без настроенного URL и при любой ошибке сети команда обязана
 *      завершаться успехом. Упавшая команда в расписании тянет за собой соседей
 *      по слоту, то есть сторож сам стал бы аварией.
 *   2) /fail при мёртвом Horizon: если ветка отвалится, «сайт жив, а очереди
 *      стоят» снова станет ненаблюдаемым — ровно та дыра, ради которой пульс
 *      и заводился.
 */
class SchedulerHeartbeatTest extends TestCase
{
    private const URL = 'https://hc-ping.com/00000000-0000-0000-0000-000000000000';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('heartbeat.url', self::URL);
        config()->set('heartbeat.timeout', 5);
        config()->set('heartbeat.check_horizon', false);
    }

    public function test_it_pings_the_success_url_when_everything_is_healthy(): void
    {
        Http::fake([self::URL => Http::response('OK', 200)]);

        $this->artisan('heartbeat:ping')->assertSuccessful();

        Http::assertSent(fn ($request) => $request->url() === self::URL);
    }

    public function test_it_sends_nothing_when_the_url_is_not_configured(): void
    {
        config()->set('heartbeat.url', '');
        Http::fake();

        $this->artisan('heartbeat:ping')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_dry_run_sends_nothing(): void
    {
        Http::fake();

        $this->artisan('heartbeat:ping', ['--dry' => true])->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_it_pings_the_fail_endpoint_when_horizon_is_not_running(): void
    {
        config()->set('heartbeat.check_horizon', true);
        $this->fakeHorizonMasters([]);

        Http::fake([self::URL.'/fail' => Http::response('OK', 200)]);

        $this->artisan('heartbeat:ping')->assertSuccessful();

        Http::assertSent(fn ($request) => $request->url() === self::URL.'/fail');
    }

    public function test_it_pings_the_fail_endpoint_when_horizon_is_paused(): void
    {
        config()->set('heartbeat.check_horizon', true);
        $this->fakeHorizonMasters([(object) ['name' => 'master-1', 'status' => 'paused']]);

        Http::fake([self::URL.'/fail' => Http::response('OK', 200)]);

        $this->artisan('heartbeat:ping')->assertSuccessful();

        Http::assertSent(fn ($request) => $request->url() === self::URL.'/fail');
    }

    public function test_a_running_horizon_keeps_the_success_url(): void
    {
        config()->set('heartbeat.check_horizon', true);
        $this->fakeHorizonMasters([(object) ['name' => 'master-1', 'status' => 'running']]);

        Http::fake([self::URL => Http::response('OK', 200)]);

        $this->artisan('heartbeat:ping')->assertSuccessful();

        Http::assertSent(fn ($request) => $request->url() === self::URL);
    }

    /**
     * Лежащий Redis не должен ронять команду: сам по себе он и есть авария,
     * о которой надо сообщить, а не причина упасть молча.
     */
    public function test_it_survives_horizon_repository_throwing(): void
    {
        config()->set('heartbeat.check_horizon', true);

        $repo = $this->createMock(MasterSupervisorRepository::class);
        $repo->method('all')->willThrowException(new \RuntimeException('Redis недоступен'));
        $this->app->instance(MasterSupervisorRepository::class, $repo);

        Http::fake([self::URL.'/fail' => Http::response('OK', 200)]);

        $this->artisan('heartbeat:ping')->assertSuccessful();

        Http::assertSent(fn ($request) => $request->url() === self::URL.'/fail');
    }

    /**
     * Сеть отвалилась — команда всё равно успешна. Пропущенный пинг сам
     * поднимет тревогу на стороне healthchecks.io, ронять планировщик незачем.
     */
    public function test_it_stays_successful_when_the_ping_cannot_be_delivered(): void
    {
        Http::fake(fn () => throw new \RuntimeException('соединение оборвалось'));

        $this->artisan('heartbeat:ping')->assertSuccessful();
    }

    /**
     * Ответ 500 от healthchecks.io — тоже не повод падать.
     */
    public function test_it_stays_successful_when_healthchecks_answers_with_an_error(): void
    {
        Http::fake([self::URL => Http::response('nope', 500)]);

        $this->artisan('heartbeat:ping')->assertSuccessful();
    }

    /** @param  array<int, object>  $masters */
    private function fakeHorizonMasters(array $masters): void
    {
        $repo = $this->createMock(MasterSupervisorRepository::class);
        $repo->method('all')->willReturn($masters);
        $this->app->instance(MasterSupervisorRepository::class, $repo);
    }
}
