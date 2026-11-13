<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Services\Support\Hermes\Quota\QuotaLane;
use App\Services\Support\Hermes\Quota\QuotaVerdict;
use App\Services\Support\Hermes\Quota\SchoolRoleQuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * H6174 (E026, hermes-24-01a10bca, class security): acceptance-обёртка
 * подписочной квоты по школьным ролям — схема 60/20/20, пороги 79/80/99/100,
 * concurrent reservation/release, максимум два повтора, на 80 стоп
 * экспериментов, на 100 ноль LLM-вызовов, платного fallback нет.
 *
 * Точка входа `php artisan test --filter=H6174HermesAcceptanceTest`
 * зарегистрирована по правилам owning repo (tests/Feature/Support, PSR-4
 * Tests\Feature\Support, автодискавери phpunit.xml). Сценарии разнесены по
 * traits/ Concerns/H6174* из лимита token-size-gate (≤20 КБ на файл) и все
 * исполняются этим классом. Обёртка над существующим валидатором бюджета
 * (AgentBudget::softWarning, H3231) — сценарий 13.
 *
 * Фикстуры — только синтетика (SQLite in-memory, конфиг/резервации):
 * ни одной production-строки, ни одного реального сообщения; квоты —
 * синтетические литералы (1000 токенов), ожидаемые значения зафиксированы
 * литералами. Канонические role ID — публичный ростер (Uprava
 * docs/FLEET_SCHOOL_AGENTS_92_ROSTER_GRILL_04-10-2026.md): первая волна
 * поддержки (`faq-rag` ID 20, `schedule-keeper` ID 23, `homework-dispatcher`
 * ID 29); исключённые ID 8/39/42 — негативный сценарий. Публичная конфигурация
 * пилота (course 341 / group 79, базлайн H6120 §2) — контекст ролей первой
 * волны; сама квота считается по ролям, не по потокам, только агрегаты.
 */
class H6174HermesAcceptanceTest extends TestCase
{
    use Concerns\H6174QuotaBudgetScenarios;
    use Concerns\H6174QuotaCallPolicyScenarios;
    use Concerns\H6174QuotaLedgerScenarios;
    use RefreshDatabase;

    /** Сентинел текста ученика — не должен попасть ни в один реестр квоты. */
    private const SENTINEL = 'Х6174-СЕНТИНЕЛ-ТЕКСТ-УЧЕНИКА';

    /** Синтетическая квота роли в токенах на окно (fixture, не прод-число). */
    private const ALLOWANCE = 1000;

    private SchoolRoleQuotaService $quota;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-12 12:00:00'));

        config()->set('school_role_quota.enabled', true);
        config()->set('school_role_quota.allowances', [
            'faq-rag' => self::ALLOWANCE,
            'schedule-keeper' => self::ALLOWANCE,
        ]);

        $this->quota = new SchoolRoleQuotaService;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** 1. Принятая схема закреплена в конфиге: 60/20/20, 79/80/99/100, ≤2 повторов, без платного fallback. */
    public function test_adopted_scheme_is_pinned_in_config(): void
    {
        $this->assertSame(
            ['routine' => 60, 'check' => 20, 'reserve' => 20],
            config('school_role_quota.lane_share_pct'),
        );
        $this->assertSame(79, config('school_role_quota.warn_pct'));
        $this->assertSame(80, config('school_role_quota.experiments_stop_pct'));
        $this->assertSame(99, config('school_role_quota.critical_pct'));
        $this->assertSame(100, config('school_role_quota.hard_stop_pct'));
        $this->assertSame(2, config('school_role_quota.max_retries'));
        $this->assertFalse((bool) config('school_role_quota.paid_fallback'));

        // Дефолт enabled — OFF в самом файле конфига (без production-включения).
        $raw = require config_path('school_role_quota.php');
        $this->assertFalse((bool) $raw['enabled']);
    }

    /**
     * Вспомогательный позитивный расход: резерв + commit (штатный вызов без
     * эксперимента), ровно $units токенов.
     */
    private function spend(QuotaLane $lane, int $units, string $requestId): QuotaVerdict
    {
        $verdict = $this->quota->reserve('faq-rag', $lane, $units, $requestId);

        if ($verdict->allowed && $verdict->reservationId !== null) {
            $this->quota->commit($verdict->reservationId, $units);
        }

        return $verdict;
    }
}
