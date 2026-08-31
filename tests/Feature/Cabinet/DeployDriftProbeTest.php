<?php

declare(strict_types=1);

namespace Tests\Feature\Cabinet;

use App\Support\Deploy\DeployDriftInspector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * H3803 — «прод перестал получать код» должен звенеть сам.
 *
 * Инцидент 31-08-2026: протухший креденшал уронил `git pull`, сайт остался
 * зелёным по всем метрикам, и отказ всплыл только когда понадобился деплой.
 * Набор пинит обе ноги проверки, и в первую очередь ту, которую наивная
 * реализация теряет: при мёртвом fetch отставание РАВНО НУЛЮ, потому что
 * ref origin/main замерзает вместе с HEAD.
 */
class DeployDriftProbeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-31 12:00:00');

        config([
            // Проверка гейтится продакшеном — набор притворяется продом.
            'app.env' => 'production',
            'cabinet_probe.check_deploy_drift' => true,
            'cabinet_probe.deploy_drift_fetch_max_age_minutes' => 90,
            'cabinet_probe.deploy_drift_behind_max_age_minutes' => 60,
            // Остальные ветки пробы молчат: набор про дрейф деплоя.
            'cabinet_probe.public_surfaces' => [],
            'cabinet_probe.surfaces' => [],
            'cabinet_probe.student_surfaces' => [],
            'cabinet_probe.check_server_guards' => false,
            'cabinet_probe.check_payment_tls' => false,
            'cabinet_probe.check_homework_upload' => false,
            'services.test_manager.email' => '',
            'services.test_manager.password' => '',
            'services.test_student.email' => '',
            'services.test_student.password' => '',
        ]);

        Http::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fakeInspector(?Carbon $lastFetch, int $behind, ?Carbon $originHead, bool $usable = true): void
    {
        $this->app->instance(DeployDriftInspector::class, new class($lastFetch, $behind, $originHead, $usable) extends DeployDriftInspector
        {
            public function __construct(
                private readonly ?Carbon $fetchAt,
                private readonly int $behindBy,
                private readonly ?Carbon $originAt,
                private readonly bool $usableFlag,
            ) {
                parent::__construct('/nonexistent');
            }

            public function isUsable(): bool
            {
                return $this->usableFlag;
            }

            public function lastFetchAt(): ?Carbon
            {
                return $this->fetchAt;
            }

            public function commitsBehind(): ?int
            {
                return $this->behindBy;
            }

            public function originHeadCommittedAt(): ?Carbon
            {
                return $this->originAt;
            }
        });
    }

    /**
     * Artisan::call, а не $this->artisan(): PendingCommand выполняется лениво,
     * и без ассерта на нём Artisan::output() возвращает ПУСТУЮ строку — а на
     * пустой строке все assertStringNotContainsString зеленеют, ничего не
     * проверив. Positive control ниже держит эту дыру закрытой.
     */
    private function probeOutput(): string
    {
        Artisan::call('cabinet:probe', ['--dry' => true, '--no-alert' => true]);
        $out = Artisan::output();

        $this->assertStringContainsString(
            'Кабинет',
            $out,
            'positive control: проба обязана была отработать — иначе «тишина» ничего не значит',
        );

        return $out;
    }

    /**
     * ГЛАВНЫЙ пин. Ровно форма инцидента: fetch мёртв, поэтому origin/main
     * замёрз и отставание НУЛЕВОЕ. Проверка, смотрящая только на «behind»,
     * здесь молчит — и потому одна она бесполезна.
     */
    public function test_dead_fetch_alarms_even_though_nothing_looks_behind(): void
    {
        $this->fakeInspector(
            lastFetch: Carbon::parse('2026-08-31 09:00:00'),
            behind: 0,
            originHead: Carbon::parse('2026-08-31 08:00:00'),
        );

        $out = $this->probeOutput();

        $this->assertStringContainsString('deploy-drift', $out);
        $this->assertStringContainsString('git fetch', $out);
    }

    public function test_fresh_fetch_and_no_drift_is_silent(): void
    {
        $this->fakeInspector(
            lastFetch: Carbon::parse('2026-08-31 11:45:00'),
            behind: 0,
            originHead: Carbon::parse('2026-08-31 11:40:00'),
        );

        $this->assertStringNotContainsString('deploy-drift', $this->probeOutput());
    }

    public function test_behind_origin_for_longer_than_the_grace_period_alarms(): void
    {
        $this->fakeInspector(
            lastFetch: Carbon::parse('2026-08-31 11:50:00'),
            behind: 3,
            originHead: Carbon::parse('2026-08-31 10:00:00'),
        );

        $out = $this->probeOutput();

        $this->assertStringContainsString('deploy-drift', $out);
        $this->assertStringContainsString('отстал от origin/main на 3', $out);
    }

    /** Только что смерженный PR — это не авария: деплой ещё едет. */
    public function test_recently_merged_commit_within_the_grace_period_is_silent(): void
    {
        $this->fakeInspector(
            lastFetch: Carbon::parse('2026-08-31 11:55:00'),
            behind: 1,
            originHead: Carbon::parse('2026-08-31 11:50:00'),
        );

        $this->assertStringNotContainsString('deploy-drift', $this->probeOutput());
    }

    public function test_missing_fetch_head_alarms(): void
    {
        $this->fakeInspector(lastFetch: null, behind: 0, originHead: null);

        $this->assertStringContainsString('FETCH_HEAD', $this->probeOutput());
    }

    /** Не git-чекаут (или git не видит origin/main) — проверка молчит. */
    public function test_unusable_checkout_is_skipped(): void
    {
        $this->fakeInspector(lastFetch: null, behind: 0, originHead: null, usable: false);

        $this->assertStringNotContainsString('deploy-drift', $this->probeOutput());
    }

    /**
     * Дев-машина отстаёт от origin/main постоянно; звенеть там — верный способ
     * приучить людей не читать вывод пробы.
     */
    public function test_non_production_is_skipped(): void
    {
        config(['app.env' => 'local']);
        $this->fakeInspector(
            lastFetch: Carbon::parse('2026-08-30 00:00:00'),
            behind: 42,
            originHead: Carbon::parse('2026-08-30 00:00:00'),
        );

        $this->assertStringNotContainsString('deploy-drift', $this->probeOutput());
    }

    public function test_flag_off_skips_the_check(): void
    {
        config(['cabinet_probe.check_deploy_drift' => false]);
        $this->fakeInspector(
            lastFetch: Carbon::parse('2026-08-30 00:00:00'),
            behind: 99,
            originHead: Carbon::parse('2026-08-30 00:00:00'),
        );

        $this->assertStringNotContainsString('deploy-drift', $this->probeOutput());
    }

    /** Дрейф деплоя не роняет деплой: сайт-то жив. Severity обязана быть soft. */
    public function test_drift_is_soft_and_does_not_fail_the_probe(): void
    {
        $this->fakeInspector(
            lastFetch: Carbon::parse('2026-08-30 00:00:00'),
            behind: 5,
            originHead: Carbon::parse('2026-08-30 00:00:00'),
        );

        $this->artisan('cabinet:probe', ['--dry' => true, '--no-alert' => true, '--fail-on-critical' => true])
            ->assertExitCode(0);
    }
}
