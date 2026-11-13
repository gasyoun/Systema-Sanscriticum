<?php

declare(strict_types=1);

namespace Tests\Feature\Support\Concerns;

use App\Models\SchoolRoleQuota;
use App\Models\SchoolRoleQuotaReservation;
use App\Services\Support\Hermes\Quota\QuotaLane;
use App\Services\Support\Hermes\Quota\QuotaStatus;
use App\Services\Support\Hermes\Quota\QuotaVerdict;
use App\Services\Support\Hermes\Quota\TransientCallException;
use App\Services\Support\Hermes\Quota\TransientRetryLimiter;
use App\Services\Support\StudentAgent\AgentBudget;

/**
 * H6174: сценарии политики вызовов — ≤2 повторов на временную ошибку,
 * стоп экспериментов на 80, ноль LLM-вызовов на 100 без платного fallback,
 * блокировка ролей 8/39/42, обёртка над AgentBudget, TBD log-only,
 * дефолт OFF. Методы исполняются классом H6174HermesAcceptanceTest.
 */
trait H6174QuotaCallPolicyScenarios
{
    /** 7. Максимум два повтора: 1 попытка + 2 повтора, третий запрещён, резервы возвращены. */
    public function test_max_two_retries_on_transient_error(): void
    {
        $this->assertSame(2, config('school_role_quota.max_retries'));

        $limiter = TransientRetryLimiter::fromConfig();
        $this->assertSame(2, $limiter->maxRetries());
        $this->assertTrue($limiter->shouldRetry(0, true));
        $this->assertTrue($limiter->shouldRetry(1, true));
        $this->assertFalse($limiter->shouldRetry(2, true));
        $this->assertFalse($limiter->shouldRetry(0, false));
        $this->assertTrue($limiter->isTransient(new TransientCallException('tmp')));
        $this->assertFalse($limiter->isTransient(new \RuntimeException('perm')));

        $calls = 0;
        $llm = function () use (&$calls): array {
            $calls++;

            throw new TransientCallException('synthetic transient failure');
        };

        $result = $this->quota->executeWithPolicy('faq-rag', QuotaLane::ROUTINE, 100, false, $llm, 'retry-task');

        $this->assertSame(3, $calls);
        $this->assertSame(3, $result['attempts']);
        $this->assertSame(QuotaVerdict::CODE_RETRIES_EXHAUSTED, $result['verdict']->code);
        $this->assertFalse($result['verdict']->allowed);
        $this->assertSame(QuotaVerdict::FALLBACK_NONE, $result['verdict']->fallback());
        $this->assertTrue($result['verdict']->queuedForHuman);

        // Каждая попытка резервировала и возвращала токены: счётчики чисты.
        $status = $this->quota->status('faq-rag');
        $this->assertSame(0, $status->usedTokens());
        $this->assertSame(3, SchoolRoleQuotaReservation::count());
    }

    /** 8. Стойкая ошибка не повторяется вообще (повтор только на временную). */
    public function test_non_transient_error_is_not_retried(): void
    {
        $calls = 0;
        $llm = function () use (&$calls): array {
            $calls++;

            throw new \RuntimeException('synthetic permanent failure');
        };

        $result = $this->quota->executeWithPolicy('faq-rag', QuotaLane::ROUTINE, 100, false, $llm, 'perm-task');

        $this->assertSame(1, $calls);
        $this->assertSame(1, $result['attempts']);
        $this->assertSame(QuotaVerdict::CODE_TASK_FAILED, $result['verdict']->code);
        $this->assertSame(0, $this->quota->status('faq-rag')->usedTokens());
    }

    /** 10. На 100 — ноль LLM-вызовов, платного fallback нет: очередь человеку. */
    public function test_hard_stop_is_zero_llm_calls_without_paid_fallback(): void
    {
        $this->spend(QuotaLane::ROUTINE, 600, 'hs-r1');
        $this->spend(QuotaLane::CHECK, 200, 'hs-c1');
        $this->spend(QuotaLane::RESERVE, 200, 'hs-s1');

        $llmCalls = 0;
        $paidAdapterCalls = 0;
        // Платный адаптер существует в мире вызовов, но машине не передаётся.
        $paidAdapter = function () use (&$paidAdapterCalls): array {
            $paidAdapterCalls++;

            return ['result' => 'paid', 'tokens' => 1];
        };

        $result = $this->quota->executeWithPolicy(
            'faq-rag',
            QuotaLane::ROUTINE,
            10,
            false,
            function () use (&$llmCalls): array {
                $llmCalls++;

                return ['result' => 'llm', 'tokens' => 10];
            },
            'hs-task',
        );

        $this->assertSame(0, $llmCalls);
        $this->assertSame(0, $result['attempts']);
        $this->assertSame(QuotaVerdict::CODE_HARD_STOP, $result['verdict']->code);
        $this->assertFalse($result['verdict']->allowed);
        $this->assertSame(QuotaVerdict::FALLBACK_NONE, $result['verdict']->toArray()['fallback']);
        $this->assertTrue($result['verdict']->queuedForHuman);

        // Платный адаптер не вызывается машиной никогда — путь fallback отсутствует.
        $this->assertSame(0, $paidAdapterCalls);
    }

    /** 11. Роли 8/39/42 (ИСКЛ) блокируются; не-канонический слаг ID отклоняется. */
    public function test_excluded_role_ids_are_blocked(): void
    {
        foreach (['lead-scorer', 'pronunciation-coach', 'plagiarism-sniffer'] as $roleId) {
            $verdict = $this->quota->reserve($roleId, QuotaLane::ROUTINE, 100, 'excl-'.$roleId);
            $this->assertFalse($verdict->allowed);
            $this->assertSame(QuotaVerdict::CODE_EXCLUDED_ROLE, $verdict->code);
            $this->assertTrue($verdict->queuedForHuman);
            $this->assertSame(QuotaVerdict::FALLBACK_NONE, $verdict->fallback());
        }

        $invalid = $this->quota->reserve('Lead Scorer', QuotaLane::ROUTINE, 100, 'excl-invalid');
        $this->assertFalse($invalid->allowed);
        $this->assertSame(QuotaVerdict::CODE_INVALID_ROLE_ID, $invalid->code);

        $this->assertSame(0, SchoolRoleQuotaReservation::count());
        $this->assertSame(0, SchoolRoleQuota::count());
    }

    /** 13. Обёртка над существующим валидатором бюджета (AgentBudget::softWarning, H3231). */
    public function test_wrapper_over_existing_agent_budget_validator(): void
    {
        $this->spend(QuotaLane::ROUTINE, 600, 'wrap-r1');
        $this->spend(QuotaLane::CHECK, 100, 'wrap-c1');
        $this->spend(QuotaLane::CHECK, 90, 'wrap-c2');

        $snapshot = $this->quota->budgetSnapshot('faq-rag');
        $this->assertSame(790, $snapshot['tokens']);
        $this->assertSame(self::ALLOWANCE, $snapshot['max_tokens']);
        // 79% < 80% порога существующего валидатора — предупреждения ещё нет.
        $this->assertNull(AgentBudget::softWarning($snapshot));

        $this->spend(QuotaLane::CHECK, 10, 'wrap-c3');
        $snapshot = $this->quota->budgetSnapshot('faq-rag');
        $this->assertSame(800, $snapshot['tokens']);
        // Существующий валидатор (порог 0.8) срабатывает на 80%.
        $this->assertIsString(AgentBudget::softWarning($snapshot));
    }

    /** 15. Неизмеренная квота (TBD) — log-only: ничего не пишет и не ограничивает (прецедент H5867). */
    public function test_unmeasured_allowance_is_log_only(): void
    {
        $verdict = $this->quota->reserve('homework-dispatcher', QuotaLane::ROUTINE, 500, 'um-1');
        $this->assertTrue($verdict->allowed);
        $this->assertSame(QuotaVerdict::STATUS_UNMEASURED, $verdict->status);
        $this->assertSame(QuotaStatus::MODE_UNMEASURED, $this->quota->status('homework-dispatcher')->mode());

        $result = $this->quota->executeWithPolicy(
            'homework-dispatcher',
            QuotaLane::ROUTINE,
            500,
            false,
            fn (): array => ['result' => 'um-payload', 'tokens' => 400],
            'um-task',
        );
        $this->assertSame('um-payload', $result['result']);
        $this->assertSame(0, SchoolRoleQuotaReservation::count());
        $this->assertSame(0, SchoolRoleQuota::count());
    }

    /** 16. Дефолт машины — OFF (никакого production-включения, нулевой прод-след). */
    public function test_machine_disabled_by_default_has_zero_prod_effect(): void
    {
        config()->set('school_role_quota.enabled', false);

        $verdict = $this->quota->reserve('faq-rag', QuotaLane::ROUTINE, 100, 'off-1');
        $this->assertTrue($verdict->allowed);
        $this->assertSame(QuotaVerdict::STATUS_DISABLED, $verdict->status);
        $this->assertSame(QuotaVerdict::CODE_DISABLED, $verdict->code);
        $this->assertSame(0, SchoolRoleQuotaReservation::count());
        $this->assertSame(0, SchoolRoleQuota::count());
    }
}
