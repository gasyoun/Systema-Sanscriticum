<?php

declare(strict_types=1);

namespace Tests\Feature\Support\Concerns;

use App\Services\Support\Hermes\Quota\QuotaLane;
use App\Services\Support\Hermes\Quota\QuotaStatus;
use App\Services\Support\Hermes\Quota\QuotaVerdict;

/**
 * H6174: сценарии бюджетной схемы — 60/20/20 (потолки полос), пороги
 * 79/80/99/100, независимость ролей. Методы исполняются классом
 * H6174HermesAcceptanceTest (см. его докблок про provenance фикстур).
 */
trait H6174QuotaBudgetScenarios
{
    /** 2. Схема 60/20/20: потолки полос 600/200/200 при квоте 1000, переполнение отклоняется. */
    public function test_budget_split_60_20_20_lane_caps(): void
    {
        $status = $this->quota->status('faq-rag');

        $this->assertSame(600, $status->laneCap(QuotaLane::ROUTINE));
        $this->assertSame(200, $status->laneCap(QuotaLane::CHECK));
        $this->assertSame(200, $status->laneCap(QuotaLane::RESERVE));

        // Полоса «штатная работа»: ровно 60%, дальше — потолок полосы.
        $this->assertTrue($this->spend(QuotaLane::ROUTINE, 600, 'split-r1')->allowed);
        $this->assertSame(
            QuotaVerdict::CODE_LANE_EXHAUSTED,
            $this->quota->reserve('faq-rag', QuotaLane::ROUTINE, 1, 'split-r2')->code,
        );

        // Полоса «проверки»: ровно 20%, дальше — потолок полосы.
        $this->assertTrue($this->spend(QuotaLane::CHECK, 200, 'split-c1')->allowed);
        $this->assertSame(
            QuotaVerdict::CODE_LANE_EXHAUSTED,
            $this->quota->reserve('faq-rag', QuotaLane::CHECK, 1, 'split-c2')->code,
        );

        // Полоса «резерв»: ровно 20% — на независимой роли, при расходе ниже порогов.
        $this->assertTrue(
            $this->quota->reserve('schedule-keeper', QuotaLane::RESERVE, 200, 'split-s1')->allowed,
        );
        $this->assertSame(
            QuotaVerdict::CODE_LANE_EXHAUSTED,
            $this->quota->reserve('schedule-keeper', QuotaLane::RESERVE, 1, 'split-s2')->code,
        );

        // Ровно 100% расхода → режим hard_stop, следующий вызов — ноль LLM-вызовов.
        $this->assertTrue($this->spend(QuotaLane::RESERVE, 200, 'split-s3')->allowed);
        $status = $this->quota->status('faq-rag');
        $this->assertSame(1000, $status->usedTokens());
        $this->assertSame(QuotaStatus::MODE_HARD_STOP, $status->mode());
        $this->assertSame(
            QuotaVerdict::CODE_HARD_STOP,
            $this->quota->reserve('faq-rag', QuotaLane::RESERVE, 1, 'split-s4')->code,
        );
    }

    /** 3. Пороги 79/80/99/100: warn → стоп экспериментов → critical → ноль вызовов. */
    public function test_thresholds_79_80_99_100_ladder(): void
    {
        $this->spend(QuotaLane::ROUTINE, 600, 'thr-r1');
        $this->spend(QuotaLane::CHECK, 100, 'thr-c1');
        $this->spend(QuotaLane::CHECK, 90, 'thr-c2');

        // 79%: предупреждение «впереди срезка 80», эксперименты ещё идут.
        $status = $this->quota->status('faq-rag');
        $this->assertSame(790, $status->usedTokens());
        $this->assertSame(QuotaStatus::MODE_WARN, $status->mode());
        $this->assertContains('approaching_experiments_cutoff_80', $status->warnings());

        // Эксперимент не должен перешагнуть 80%: 790+10 → ровно 80% → стоп.
        $this->assertSame(
            QuotaVerdict::CODE_EXPERIMENTS_STOPPED,
            $this->quota->reserve('faq-rag', QuotaLane::RESERVE, 10, 'thr-exp1', isExperiment: true)->code,
        );
        // Эксперимент, остающийся ниже 80% (790+5 → 79.5%), допустим.
        $ok = $this->quota->reserve('faq-rag', QuotaLane::RESERVE, 5, 'thr-exp2', isExperiment: true);
        $this->assertTrue($ok->allowed);
        $this->quota->release((int) $ok->reservationId);

        // 80%: стоп экспериментов, штатная работа продолжается.
        $this->spend(QuotaLane::CHECK, 10, 'thr-c3');
        $status = $this->quota->status('faq-rag');
        $this->assertSame(800, $status->usedTokens());
        $this->assertSame(QuotaStatus::MODE_EXPERIMENTS_STOPPED, $status->mode());
        $this->assertTrue($status->experimentsStopped());
        $this->assertSame(
            QuotaVerdict::CODE_EXPERIMENTS_STOPPED,
            $this->quota->reserve('faq-rag', QuotaLane::RESERVE, 1, 'thr-exp3', isExperiment: true)->code,
        );
        $this->assertTrue($this->spend(QuotaLane::RESERVE, 100, 'thr-s1')->allowed);

        // 99%: критическое предупреждение «впереди срезка 100».
        $this->spend(QuotaLane::RESERVE, 90, 'thr-s2');
        $status = $this->quota->status('faq-rag');
        $this->assertSame(990, $status->usedTokens());
        $this->assertSame(QuotaStatus::MODE_CRITICAL, $status->mode());
        $this->assertContains('approaching_hard_cutoff_100', $status->warnings());
        $this->assertContains('approaching_experiments_cutoff_80', $status->warnings());

        // Вызов, перешагивающий квоту (990+20 > 1000), — отказ уже на подходе к 100.
        $this->assertSame(
            QuotaVerdict::CODE_HARD_STOP,
            $this->quota->reserve('faq-rag', QuotaLane::RESERVE, 20, 'thr-over1')->code,
        );

        // 100%: ноль LLM-вызовов — любой вызов отклонён.
        $this->spend(QuotaLane::RESERVE, 10, 'thr-s3');
        $status = $this->quota->status('faq-rag');
        $this->assertSame(1000, $status->usedTokens());
        $this->assertSame(QuotaStatus::MODE_HARD_STOP, $status->mode());
        $this->assertSame(
            QuotaVerdict::CODE_HARD_STOP,
            $this->quota->reserve('faq-rag', QuotaLane::ROUTINE, 1, 'thr-after')->code,
        );
        $this->assertSame(
            QuotaVerdict::CODE_HARD_STOP,
            $this->quota->reserve('faq-rag', QuotaLane::RESERVE, 1, 'thr-after2')->code,
        );
    }

    /** 14. Роли независимы: исчерпание одной роли не блокирует другую. */
    public function test_roles_have_independent_budgets(): void
    {
        $this->spend(QuotaLane::ROUTINE, 600, 'iso-r1');
        $this->spend(QuotaLane::CHECK, 200, 'iso-c1');
        $this->spend(QuotaLane::RESERVE, 200, 'iso-s1');
        $this->assertSame(QuotaStatus::MODE_HARD_STOP, $this->quota->status('faq-rag')->mode());

        $other = $this->quota->reserve('schedule-keeper', QuotaLane::ROUTINE, 600, 'iso-other');
        $this->assertTrue($other->allowed);
        $this->assertSame(QuotaStatus::MODE_OK, $this->quota->status('schedule-keeper')->mode());
    }
}
