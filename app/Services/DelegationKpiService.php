<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\FinanceCockpitReport;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * KPI-панель делегирования (H259, фаза D плана noboring /cases/education).
 *
 * Главная цель, заданная MG: вывести собственника из операционки. Кейс «Аура»:
 * после прозрачной отчётности + KPI делегированный оператор «уверенно перенял
 * рычаги», у собственника осталось ≤1 встречи в неделю. Эта панель — тот самый
 * один экран: она сводит главные цифры всех четырёх фаз со светофорами, чтобы
 * оператор/бухгалтер вёл бизнес, НЕ ЗАХОДЯ к собственнику.
 *
 * Урок-предупреждение из антикейса «Лингвистик»: идеальный финплан не спас школу,
 * потому что его никто не внедрил — не было владельца цифр и ритма обзора.
 * Поэтому панель дополнена ритмом обзора (docs/FINANCE_REVIEW_RHYTHM.md) и
 * недельным дайджестом (finance:kpi-digest).
 *
 * Ничего не считает сама — переиспользует сервисы фаз:
 *  A — StudentUnitEconomicsService (LTV/CAC, окупаемость привлечения);
 *  B — FinanceCockpitReport (прибыль по начислению, отложенная выручка);
 *  C — ReceivablesGovernanceService (дебиторка против порога);
 *  D — ProfitFundsService (резервный фонд, обеспеченность кассой).
 */
class DelegationKpiService
{
    public function __construct(
        private readonly StudentUnitEconomicsService $unitEconomics,
        private readonly FinanceCockpitReport $report,
        private readonly ReceivablesGovernanceService $receivables,
        private readonly ProfitFundsService $funds,
    ) {}

    /**
     * Сводка KPI по всем фазам: список карточек (метрика + значение + светофор +
     * ссылка на подробную страницу) и общий цвет панели.
     *
     * @return array<string, mixed>
     */
    public function snapshot(?Carbon $asOf = null): array
    {
        $asOf = ($asOf ?? now())->copy();
        $period = $asOf->format('Y-m');

        $cards = [
            $this->unitEconomicsCard($asOf),
            $this->accrualProfitCard($period),
            $this->deferredRevenueCard($period),
            $this->receivablesCard(),
            $this->reserveFundCard($asOf),
            $this->bdrCard(),
        ];

        // Общий цвет панели = худший светофор среди «живых» карточек (не gray/
        // ожидающих). Оператор одним взглядом видит, спокойно ли всё.
        $rank = ['success' => 0, 'gray' => 0, 'warning' => 1, 'danger' => 2];
        $worst = 'success';
        foreach ($cards as $card) {
            if (($rank[$card['level']] ?? 0) > ($rank[$worst] ?? 0)) {
                $worst = $card['level'];
            }
        }

        return [
            'as_of' => $asOf->toDateTimeString(),
            'period' => $period,
            'cards' => $cards,
            'level' => $worst,
            'ok' => $worst !== 'danger',
            'alerts' => array_values(array_filter(array_map(
                fn (array $c) => $c['level'] === 'danger' ? $c['label'].': '.$c['value'] : null,
                $cards,
            ))),
        ];
    }

    /** Фаза A — окупаемость привлечения (LTV/CAC) по когорте последних 90 дней. */
    private function unitEconomicsCard(Carbon $asOf): array
    {
        $data = $this->unitEconomics->forPeriod($asOf->copy()->subDays(90), $asOf);

        if (! ($data['found'] ?? false) || ($data['ltv_cac_ratio'] ?? null) === null) {
            return $this->card('unit_economics', 'A · Окупаемость привлечения (LTV/CAC)',
                'нет данных за 90 дн.', 'gray', 'student-unit-economics',
                'Ученики привлекаются дороже, чем окупаются — красный флаг маркетинга.');
        }

        $ratio = $data['ltv_cac_ratio'];
        $payback = $data['months_to_payback'];

        return $this->card(
            'unit_economics',
            'A · Окупаемость привлечения (LTV/CAC)',
            'LTV/CAC '.number_format((float) $ratio, 1, '.', ' ')
                .($payback !== null ? ' · окупаемость '.number_format((float) $payback, 1, '.', ' ').' мес' : ''),
            $data['ltv_cac_color'] ?? 'gray',
            'student-unit-economics',
            'LTV/CAC ниже 1 — привлечение убыточно; ниже 3 — тонкая экономика.',
        );
    }

    /** Фаза B — чистая прибыль месяца по методу начисления. */
    private function accrualProfitCard(string $period): array
    {
        $opiu = $this->report->opiuAccrual($period);
        $ebitda = (float) $opiu['ebitda'];
        $margin = $opiu['ebitdaMargin'];

        $level = $ebitda > 0 ? 'success' : ($ebitda < 0 ? 'danger' : 'gray');

        return $this->card(
            'accrual_profit',
            'B · Прибыль месяца (начисление)',
            $this->rub($ebitda).($margin !== null ? ' · маржа '.number_format((float) $margin, 1, '.', ' ').' %' : ''),
            $level,
            'finance-cockpit',
            'Прибыль по признанной выручке, а не по кассе. Отрицательная — месяц убыточен.',
        );
    }

    /** Фаза B — отложенная выручка (оплачено, но ещё не отработано). */
    private function deferredRevenueCard(string $period): array
    {
        $deferred = (float) $this->report->deferredRevenue($period)['deferred'];

        // Положительная отложенная выручка — норма (предоплата вперёд); это не
        // тревога, а напоминание: эти деньги ещё нужно отработать. Красным не
        // светим — только серым/зелёным информером.
        return $this->card(
            'deferred_revenue',
            'B · Отложенная выручка (обязательство)',
            $this->rub($deferred),
            'gray',
            'finance-cockpit',
            'Оплачено вперёд, но ещё не отработано. Это обязательство, не прибыль — не тратьте как свои.',
        );
    }

    /** Фаза C — дебиторка против порога максимально допустимой. */
    private function receivablesCard(): array
    {
        $snap = $this->receivables->snapshot();
        $ratioPct = $snap['ratio_pct'];

        return $this->card(
            'receivables',
            'C · Дебиторка против порога',
            $this->rub((float) $snap['total'])
                .($ratioPct !== null ? ' · '.number_format((float) $ratioPct, 0, '.', ' ').' % порога' : ''),
            $snap['level'],
            'receivables-governance',
            'Дебиторка выше порога или растёт неликвид — риск кассового разрыва (антикейс «Алохомора»).',
        );
    }

    /** Фаза D — резервный фонд и его обеспеченность кассой. */
    private function reserveFundCard(Carbon $asOf): array
    {
        $snap = $this->funds->snapshot($asOf);
        $reserveKey = $snap['reserve_key'];
        $reserve = (float) ($snap['accumulated'][$reserveKey] ?? 0.0);
        $coverPct = $snap['cash_covers_reserve_pct'];

        return $this->card(
            'reserve_fund',
            'D · Резервный фонд (подушка)',
            $this->rub($reserve)
                .($coverPct !== null ? ' · касса покрывает '.number_format((float) $coverPct, 0, '.', ' ').' %' : ''),
            $snap['reserve_level'],
            'profit-funds',
            'Резерв без реальной кассы под ним — фикция; подушка должна быть деньгами.',
        );
    }

    /**
     * Фаза D — БДР план-факт. Мягко заблокирован до выбора канонического
     * бюджет-шаблона (@DECIDE MG, открытый вопрос №3). Показываем явным серым
     * информером, а не молчим — чтобы пункт не потерялся.
     */
    private function bdrCard(): array
    {
        return $this->card(
            'bdr',
            'D · БДР план-факт',
            'ожидает выбора бюджет-шаблона (@DECIDE)',
            'gray',
            'finance-planning',
            'План-факт по бюджету включится, когда MG выберет канонический шаблон бюджета.',
        );
    }

    /**
     * @return array{key:string, label:string, value:string, level:string, page:string, red_flag:string}
     */
    private function card(string $key, string $label, string $value, string $level, string $page, string $redFlag): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'level' => $level,
            'page' => $page,
            'red_flag' => $redFlag,
        ];
    }

    private function rub(float $v): string
    {
        return number_format(Money::round($v), 0, '.', ' ').' ₽';
    }
}
