<?php

declare(strict_types=1);

namespace App\Services;

use App\Console\Commands\CheckReceivablesThreshold;
use App\Filament\Pages\Debtors;
use App\Models\Payment;
use App\Models\PaymentPromise;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Контроль рассрочки и дебиторки (H257, фаза C плана noboring /cases/education).
 *
 * Витрина должников ({@see Debtors}) показывает, КТО должен;
 * этот сервис отвечает на управленческий вопрос — «не пробили ли мы потолок
 * дебиторки и лимиты рассрочки», то есть строит контур управления, а не список.
 *
 * Дебиторка здесь = сумма НЕПОГАШЕННЫХ обещаний оплаты (payment_promises со
 * статусом active/expired и заданной суммой). Обещания с installment_group_id —
 * дебиторка ОТ РАССРОЧКИ, одиночные — прочая. Порог и лимиты — config/receivables.php.
 *
 * Ничего не хранит — считает из живой БД. Неделя-к-неделе восстанавливается из
 * самого леджера обещаний (created_at + fulfilled_at/cancelled_at), без снапшот-
 * таблицы: «дебиторка на дату D» = обещания, созданные ≤ D и ещё не закрытые к D.
 *
 * Смысл — из антикейса школы «Алохомора»: рассрочка без контроля финдира → 2 млн ₽
 * неликвидной дебиторки за 3 недели → кассовый разрыв. Этот сервис — замена
 * ручного мониторинга: он же питает демон-алерт {@see CheckReceivablesThreshold}.
 */
class ReceivablesGovernanceService
{
    /** Тарифы-оттоки, не образующие выручку (для знаменателя порога). */
    private const NON_REVENUE_TARIFFS = ['Расход', 'salary_payout', 'deposit', 'trial'];

    /**
     * Полный снимок состояния дебиторки/рассрочки на момент $asOf (по умолчанию —
     * сейчас). Возвращает всё, что нужно странице и демон-алерту.
     *
     * @return array<string, mixed>
     */
    public function snapshot(?Carbon $asOf = null): array
    {
        $asOf = ($asOf ?? now())->copy();

        // Один проход по всем «живым» обещаниям с суммой — из него и текущие
        // цифры, и разрез рассрочка/прочее, и неликвид.
        $outstanding = $this->outstandingPromises($asOf);

        $total = $this->sumAmount($outstanding);
        $installment = $this->sumAmount($outstanding->filter->isPartOfInstallment());
        $other = $total - $installment;

        // Неликвид: просрочка сверх N дней от обещанной даты.
        $illiquidDays = (int) config('receivables.illiquid_after_days', 14);
        $illiquidCutoff = $asOf->copy()->subDays($illiquidDays)->startOfDay();
        $overduePromises = $outstanding->filter(
            fn (PaymentPromise $p) => $p->promised_at !== null
                && $p->promised_at->lt($illiquidCutoff)
        );
        $overdue = $this->sumAmount($overduePromises);
        $overduePct = $total > 0 ? $overdue / $total * 100 : 0.0;

        // Порог «максимально допустимой дебиторки».
        [$threshold, $thresholdBasis] = $this->threshold($asOf);
        $ratio = $threshold > 0 ? $total / $threshold : null;

        // Неделя-к-неделе (тот же леджер на 7 дней назад).
        $weekAgo = $asOf->copy()->subDays(7);
        $prevOutstanding = $this->outstandingPromises($weekAgo);
        $prevTotal = $this->sumAmount($prevOutstanding);
        $prevOverdue = $this->sumAmount($prevOutstanding->filter(
            fn (PaymentPromise $p) => $p->promised_at !== null
                && $p->promised_at->lt($weekAgo->copy()->subDays($illiquidDays)->startOfDay())
        ));
        $prevOverduePct = $prevTotal > 0 ? $prevOverdue / $prevTotal * 100 : 0.0;
        $deltaAbs = $total - $prevTotal;
        $overduePctRising = $overduePct > $prevOverduePct + 0.01;

        // Лимиты рассрочки.
        $limits = $this->installmentLimits($asOf);

        // ── Алерт: красный, если пробит порог ИЛИ растёт доля просрочки ИЛИ
        //    пробит любой лимит рассрочки. Это и есть замена ручного контроля. ──
        $alerts = [];
        if ($ratio !== null && $ratio >= 1.0) {
            $alerts[] = 'Дебиторка превысила порог: '.$this->rub($total).' из '.$this->rub($threshold).'.';
        }
        if ($overduePctRising && $overdue > 0) {
            $alerts[] = 'Доля неликвидной (просроченной > '.$illiquidDays.' дн.) дебиторки растёт: '
                .$this->pct($prevOverduePct).' → '.$this->pct($overduePct).'.';
        }
        foreach ($limits as $limit) {
            if ($limit['breached']) {
                $alerts[] = $limit['alert'];
            }
        }

        $level = $this->alertLevel($ratio, $alerts);

        return [
            'as_of' => $asOf->toDateTimeString(),

            // Текущая дебиторка и разрез.
            'total' => Money::round($total),
            'installment' => Money::round($installment),
            'other' => Money::round($other),
            'installment_share_of_debt_pct' => $total > 0 ? round($installment / $total * 100, 1) : 0.0,

            // Неликвид.
            'illiquid_days' => $illiquidDays,
            'overdue' => Money::round($overdue),
            'overdue_pct' => round($overduePct, 1),
            'overdue_count' => $overduePromises->count(),

            // Порог.
            'threshold' => Money::round($threshold),
            'threshold_basis' => $thresholdBasis,
            'ratio' => $ratio === null ? null : round($ratio, 2),
            'ratio_pct' => $ratio === null ? null : round($ratio * 100, 1),

            // Неделя-к-неделе.
            'prev_total' => Money::round($prevTotal),
            'delta_abs' => Money::round($deltaAbs),
            'delta_pct' => $prevTotal > 0 ? round($deltaAbs / $prevTotal * 100, 1) : null,
            'prev_overdue_pct' => round($prevOverduePct, 1),
            'overdue_pct_rising' => $overduePctRising,

            // Лимиты рассрочки.
            'limits' => $limits,

            // Итог: цвет светофора + человекочитаемые причины алерта.
            // ok = «нет пробоя» (зелёный ИЛИ жёлтый): красный бейдж в меню и
            // демон-алерт финдиру срабатывают только на реальном превышении
            // (danger), а не на приближении к порогу (warning).
            'level' => $level,
            'alerts' => $alerts,
            'ok' => $level !== 'danger',
        ];
    }

    /** Уровень светофора: только цвет, без причин (для быстрых проверок/бейджа). */
    public function alertLevel(?float $ratio, array $alerts): string
    {
        if (! empty($alerts)) {
            return 'danger';
        }
        if ($ratio === null) {
            return 'gray';
        }
        $warn = (float) config('receivables.warn_ratio', 0.8);

        return $ratio >= 1.0 ? 'danger' : ($ratio >= $warn ? 'warning' : 'success');
    }

    /**
     * Непогашенные обещания (active/expired, с суммой) по состоянию на $asOf.
     * Историю восстанавливаем из леджера: обещание «висело» на дату D, если оно
     * создано ≤ D и ещё не закрыто (fulfilled/cancelled) к D.
     *
     * @return Collection<int, PaymentPromise>
     */
    private function outstandingPromises(Carbon $asOf): Collection
    {
        $end = $asOf->copy()->endOfDay();

        // Кандидаты: всё, что создано к дате и имеет сумму. Статус/закрытие
        // разбираем в PHP — на дату D «выполненное завтра» ещё было долгом.
        return PaymentPromise::query()
            ->whereNotNull('amount')
            ->where('created_at', '<=', $end)
            ->get(['id', 'amount', 'status', 'promised_at', 'installment_group_id', 'created_at', 'fulfilled_at', 'cancelled_at'])
            ->filter(fn (PaymentPromise $p) => $this->wasOutstandingAt($p, $end))
            ->values();
    }

    /**
     * Было ли обещание непогашенным (ждущим денег) на конец дня $end.
     *
     * Долгом на дату оно является, если к этому моменту не было закрыто:
     *  - выполнено (fulfilled) с fulfilled_at ≤ end → уже НЕ долг;
     *  - отменено (cancelled) с cancelled_at ≤ end → уже НЕ долг;
     *  - active/expired → долг (деньги так и не пришли).
     * Если статус закрывающий, но метка времени отсутствует (легаси-строка) —
     * считаем закрытым как сейчас, чтобы не завышать текущую дебиторку.
     */
    private function wasOutstandingAt(PaymentPromise $p, Carbon $end): bool
    {
        if ($p->status === PaymentPromise::STATUS_FULFILLED) {
            return $p->fulfilled_at !== null && $p->fulfilled_at->gt($end);
        }
        if ($p->status === PaymentPromise::STATUS_CANCELLED) {
            return $p->cancelled_at !== null && $p->cancelled_at->gt($end);
        }

        // active / expired — деньги не пришли, это долг.
        return true;
    }

    /** @param  Collection<int, PaymentPromise>  $promises */
    private function sumAmount(Collection $promises): float
    {
        return (float) $promises->sum(fn (PaymentPromise $p) => (float) $p->amount);
    }

    /**
     * Порог «максимально допустимой дебиторки» на дату $asOf + человеческое
     * пояснение, как он получен.
     *
     * @return array{0: float, 1: string}
     */
    private function threshold(Carbon $asOf): array
    {
        $mode = (string) config('receivables.threshold.mode', 'revenue_share');

        if ($mode === 'fixed') {
            $fixed = (float) config('receivables.threshold.fixed_amount', 500000);

            return [$fixed, 'фиксированный порог из финмодели'];
        }

        // revenue_share: доля выручки за trailing-окно.
        $share = (float) config('receivables.threshold.revenue_share', 0.5);
        $windowDays = (int) config('receivables.threshold.revenue_window_days', 30);
        $revenue = $this->revenueForWindow($asOf, $windowDays);
        $threshold = $revenue * $share;

        return [
            $threshold,
            round($share * 100).' % выручки за '.$windowDays.' дн. ('.$this->rub($revenue).')',
        ];
    }

    /** Выручка (реальные школьные оплаты, кроме оттоков/предоплат) за окно. */
    private function revenueForWindow(Carbon $asOf, int $windowDays): float
    {
        $from = $asOf->copy()->subDays($windowDays);

        return (float) Payment::query()
            ->paid()
            ->real()
            ->whereNotIn('tariff', self::NON_REVENUE_TARIFFS)
            ->whereBetween('created_at', [$from, $asOf->copy()->endOfDay()])
            ->sum('amount');
    }

    /**
     * Текущее состояние трёх лимитов рассрочки против настроенных потолков.
     *
     * @return array<int, array{key:string, label:string, current:float|int|null, limit:float|int, unit:string, breached:bool, alert:string}>
     */
    private function installmentLimits(Carbon $asOf): array
    {
        $cfg = (array) config('receivables.installment_limits', []);
        $maxShare = (float) ($cfg['max_share_pct'] ?? 30);
        $maxConcurrent = (int) ($cfg['max_concurrent'] ?? 50);
        $maxTerm = (int) ($cfg['max_term_months'] ?? 3);

        // Активные планы = группы, в которых есть хоть одно active-обещание.
        $activeGroups = PaymentPromise::query()
            ->whereNotNull('installment_group_id')
            ->where('status', PaymentPromise::STATUS_ACTIVE)
            ->where('created_at', '<=', $asOf->copy()->endOfDay())
            ->get(['installment_group_id', 'promised_at'])
            ->groupBy('installment_group_id');
        $concurrent = $activeGroups->count();

        // Срок каждого активного плана — от первого до последнего обещания, мес.
        $maxTermCurrent = 0;
        foreach ($activeGroups as $rows) {
            $dates = $rows->pluck('promised_at')->filter();
            if ($dates->count() < 2) {
                continue;
            }
            $term = (int) ceil($dates->min()->diffInDays($dates->max()) / 30);
            $maxTermCurrent = max($maxTermCurrent, $term);
        }

        // Доля продаж в рассрочку: сколько планов заведено за окно порога против
        // числа привлечений (реальных оплат курса) за то же окно.
        $windowDays = (int) config('receivables.threshold.revenue_window_days', 30);
        $from = $asOf->copy()->subDays($windowDays);
        $plansInWindow = PaymentPromise::query()
            ->whereNotNull('installment_group_id')
            ->whereBetween('created_at', [$from, $asOf->copy()->endOfDay()])
            ->distinct()
            ->count('installment_group_id');
        $salesInWindow = Payment::query()
            ->paid()
            ->real()
            ->whereNotIn('tariff', self::NON_REVENUE_TARIFFS)
            ->whereBetween('created_at', [$from, $asOf->copy()->endOfDay()])
            ->count();
        $sharePct = $salesInWindow > 0 ? $plansInWindow / $salesInWindow * 100 : null;

        return [
            [
                'key' => 'share',
                'label' => 'Доля продаж в рассрочку',
                'current' => $sharePct === null ? null : round($sharePct, 1),
                'limit' => $maxShare,
                'unit' => '%',
                'breached' => $sharePct !== null && $sharePct > $maxShare,
                'alert' => 'Доля продаж в рассрочку '.$this->pct($sharePct ?? 0)
                    .' превысила лимит '.$this->pct($maxShare).'.',
            ],
            [
                'key' => 'concurrent',
                'label' => 'Одновременных рассрочек',
                'current' => $concurrent,
                'limit' => $maxConcurrent,
                'unit' => 'шт',
                'breached' => $concurrent > $maxConcurrent,
                'alert' => 'Одновременных планов рассрочки '.$concurrent
                    .' — превышен лимит '.$maxConcurrent.'.',
            ],
            [
                'key' => 'term',
                'label' => 'Макс. срок плана',
                'current' => $maxTermCurrent,
                'limit' => $maxTerm,
                'unit' => 'мес',
                'breached' => $maxTermCurrent > $maxTerm,
                'alert' => 'Есть план рассрочки на '.$maxTermCurrent
                    .' мес — дольше лимита '.$maxTerm.' мес.',
            ],
        ];
    }

    private function rub(float $v): string
    {
        return number_format($v, 0, '.', ' ').' ₽';
    }

    private function pct(float $v): string
    {
        return number_format($v, 1, '.', ' ').' %';
    }
}
