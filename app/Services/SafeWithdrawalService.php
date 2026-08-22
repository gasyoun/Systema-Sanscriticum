<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Payment;
use App\Models\TeacherPayout;
use App\Models\User;
use App\Services\Payments\TochkaBalanceService;
use App\Support\FinanceCockpitReport;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * «Сколько можно взять себе» — практика «Нескучных финансов», READ ONLY.
 *
 * ДОСТУПНО К ВЫВОДУ =
 *   балансы (Точка живьём; PayPal — честно вручную)
 *   − обязательства горизонта (преподаватели из недельной сетки календаря,
 *     персонал по ставкам, повторяемые opex)
 *   − налоговый резерв (УСН 6% кассово за квартал; НДФЛ агента; взносы за
 *     сотрудницу — двумя схемами рядом; взносы ИП за себя фикс+1%)
 *   − операционный резерв (N месяцев среднемесячных расходов).
 *
 * Никогда не пишет teacher_payouts / payments. Все допущения помечены
 * assumption и видны на экране построчно. Двойной экран итога:
 * консервативный (взносы 30%) и МСП-вариант (15% свыше МРОТ).
 */
final class SafeWithdrawalService
{
    public function __construct(
        private readonly TochkaBalanceService $tochka,
        private readonly TeacherWeeklyPayoutCalendarService $calendar,
        private readonly PayrollContourService $contour,
        private readonly FinanceCockpitReport $report,
    ) {}

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $paymentsBefore = Payment::query()->count();
        $payoutsBefore = TeacherPayout::query()->count();

        $cfg = config('safe_withdrawal');
        $horizonDays = max(1, (int) $cfg['horizon_days']);
        $now = now();

        // ── Балансы ──────────────────────────────────────────────────────────
        $tochka = $this->tochka->snapshot();
        $balances = [
            'tochka_closing' => $tochka['ok'] ? round((float) $tochka['closing_total'], 2) : null,
            'tochka_ok' => (bool) $tochka['ok'],
            'paypal' => self::COVER_PAYPAL,
        ];

        // ── Обязательства горизонта ──────────────────────────────────────────
        $year = (int) $now->year;
        $grid = $this->calendar->grid($year);
        $teacherRub = 0.0;
        $teacherEurDue = false;
        foreach ($grid['weeks'] as $week) {
            $start = Carbon::parse($week['start']);
            if ($start->gt($now->copy()->addDays($horizonDays))) {
                break;
            }
            if ($start->lt($now->copy()->startOfDay())) {
                continue;
            }
            foreach ($week['due'] as $due) {
                if (($due['lane'] ?? 'RUB') === 'EUR') {
                    $teacherEurDue = true;

                    continue;
                }
                $teacherRub += max(0.0, (float) ($due['balance'] ?? 0));
            }
        }

        $staff = $this->contour->staffPayees();
        $staffMonthly = 0.0;
        foreach ($staff['payees'] as $p) {
            if ($p['category'] === 'персонал' && $p['monthly_rate'] !== null) {
                $staffMonthly += (float) $p['monthly_rate'];
            }
        }
        $staffHorizonMonths = (int) ceil($horizonDays / 30);
        $staffObligation = round($staffMonthly * $staffHorizonMonths, 2);

        [$opexMonthly, $opexAssumption] = $this->opexMonthly();

        $opexObligation = round($opexMonthly * ($horizonDays / 30), 2);
        $obligations = [
            'teachers_rub' => round($teacherRub, 2),
            'teachers_eur_due' => $teacherEurDue,
            'staff_monthly' => round($staffMonthly, 2),
            'staff_horizon_months' => $staffHorizonMonths,
            'staff_total' => $staffObligation,
            'opex_monthly' => round($opexMonthly, 2),
            'opex_assumption' => $opexAssumption,
            'opex_total' => $opexObligation,
            'total' => round($teacherRub + $staffObligation + $opexObligation, 2),
        ];

        // ── Налоговый резерв ────────────────────────────────────────────────
        $quarterStart = $now->copy()->firstOfQuarter()->startOfDay();
        $qtdRevenue = $this->cashRevenueBetween($quarterStart, $now);

        $usnGross = round((float) $qtdRevenue * (float) $cfg['usn_rate'], 2);
        $usnReserve = $usnGross; // уплаченные авансы квартала в LMS не видны → консервативно не вычитаем

        // НДФЛ агента + взносы за сотрудницу: от ставки единственного «персонала».
        // Единственная штатная сотрудница — ставка берётся как весь staffMonthly.
        $salaryMonth = round($staffMonthly, 2);
        $ndfl = round($salaryMonth * $staffHorizonMonths * (float) $cfg['ndfl_rate'], 2);

        $insGeneral = round($salaryMonth * (float) $cfg['insurance_general_rate'] * $staffHorizonMonths, 2);
        $mrot = (float) $cfg['mrot_monthly'];
        $supper = (float) $cfg['msp_supper_rate'];
        $insMsp = round(($salaryMonth <= $mrot
            ? $salaryMonth * (float) $cfg['insurance_general_rate']
            : $mrot * (float) $cfg['insurance_general_rate'] + ($salaryMonth - $mrot) * $supper) * $staffHorizonMonths, 2);

        $ipFixed = round((float) $cfg['ip_fixed_yearly'] / 12 * min(12, (int) ceil($horizonDays / 30)), 2);
        $yearIncomeProxy = $this->yearIncomeProxy($now);
        $ipExtra = round(max(0.0, $yearIncomeProxy - (float) $cfg['ip_extra_threshold'])
            * (float) $cfg['ip_extra_rate'] / 12 * min(12, (int) ceil($horizonDays / 30)), 2);

        $taxes = [
            'usn_qtd_revenue' => round((float) $qtdRevenue, 2),
            'usn_gross' => $usnGross,
            'usn_reserve' => $usnReserve,
            'usn_note' => 'авансы, уже уплаченные за квартал, в LMS не видны — резерв без их вычета (консервативно); уменьшение страховыми взносами ≤50 % показано справкой',
            'usn_offset_note' => $this->usnOffsetNote($usnGross, $insGeneral),
            'ndfl' => $ndfl,
            'insurance_general' => $insGeneral,
            'insurance_msp' => $insMsp,
            'ip_fixed' => $ipFixed,
            'ip_extra_income_proxy_year' => round((float) $yearIncomeProxy, 2),
            'ip_extra' => $ipExtra,
        ];

        // ── Операционный резерв ─────────────────────────────────────────────
        $avgExpenses = $this->avgMonthlyExpenses($now);
        $opReserve = round($avgExpenses * (float) $cfg['op_reserve_months'], 2);

        $deductConservative = $obligations['total'] + $usnReserve + $ndfl + $insGeneral + $ipFixed + $ipExtra + $opReserve;
        $deductMsp = $obligations['total'] + $usnReserve + $ndfl + $insMsp + $ipFixed + $ipExtra + $opReserve;

        $balanceTotal = (float) ($balances['tochka_closing'] ?? 0);

        $moved = Payment::query()->count() !== $paymentsBefore
            || TeacherPayout::query()->count() !== $payoutsBefore;

        return [
            'as_of' => $now->toDateTimeString(),
            'balances' => $balances,
            'balance_total' => round($balanceTotal, 2),
            'obligations' => $obligations,
            'taxes' => $taxes,
            'op_reserve' => [
                'months' => (float) $cfg['op_reserve_months'],
                'avg_monthly_expenses' => round($avgExpenses, 2),
                'total' => $opReserve,
            ],
            'available_general' => round($balanceTotal - $deductConservative, 2),
            'available_msp' => round($balanceTotal - $deductMsp, 2),
            'money_tables_moved' => $moved,
        ];
    }

    /** Поступления кассово (LMS-paid revenue) между датами — прокси банковских поступлений. */
    private function cashRevenueBetween(Carbon $from, Carbon $to): float
    {
        return (float) Payment::query()
            ->paid()
            ->real()
            ->schoolReceived()
            ->whereNotIn('tariff', TeacherSalaryService::NON_REVENUE_TARIFFS)
            ->where('amount', '>', 0)
            ->whereBetween('created_at', [$from, $to->copy()->endOfDay()])
            ->sum('amount');
    }

    private function yearIncomeProxy(Carbon $now): float
    {
        $ytd = $this->cashRevenueBetween($now->copy()->startOfYear(), $now);
        $monthsElapsed = max(1, (int) $now->month);

        return $ytd / $monthsElapsed * 12;
    }

    /**
     * Среднемесячные расходы для операционного резерва: ОПиУ штурвала за
     * последние 3 месяца (расходная часть), fallback — средний «Расход».
     *
     * @return array{0: float, 1: bool} [сумма, assumption]
     */
    private function avgMonthlyExpenses(Carbon $now): float
    {
        $sums = [];
        for ($i = 1; $i <= 3; $i++) {
            $m = $now->copy()->subMonthsNoOverflow($i)->format('Y-m');
            $dds = $this->report->dds($m);
            $out = abs(min(0.0, (float) ($dds['net'] ?? 0)));
            if ($out > 0) {
                $sums[] = $out;
            }
        }
        if ($sums === []) {
            $since = $now->copy()->subMonths(6)->startOfMonth();
            $avg = (float) DB::table('payments')
                ->where('tariff', 'Расход')
                ->whereIn('status', Payment::PAID_STATUSES)
                ->whereNull('refund_of_payment_id')
                ->where('created_at', '>=', $since)
                ->sum('amount');

            return abs($avg) / 6;
        }

        return array_sum($sums) / count($sums);
    }

    private function usnOffsetNote(float $usnGross, float $insGeneral): string
    {
        $half = Money::round($usnGross / 2);
        $covered = min($half, $insGeneral);

        return sprintf(
            'взносы horizon-строки могут уменьшить УСН максимум на 50%% (до %.2f ₽); в этой строке покрыто %.2f ₽',
            $half,
            $covered
        );
    }

    private function opexMonthly(): array
    {
        $override = config('safe_withdrawal.opex_monthly_override');
        if ($override !== null && $override !== '') {
            return [(float) $override, false];
        }

        // Fallback: среднее «Расхода» за 6 месяцев минус получатели зарплатного
        // контура (их обязательства уже учтены отдельными строками). Смешанный
        // opex и разовые получатели НЕ исключаются — это и есть прочие расходы.
        $staffIds = [];
        foreach ($this->contour->staffPayees()['payees'] as $p) {
            if ($p['category'] === 'персонал') {
                $staffIds[] = (int) $p['user_id'];
            }
        }
        $excluded = User::query()->whereNotNull('teacher_id')->pluck('id')
            ->merge($staffIds)
            ->unique();

        $sum = (float) DB::table('payments')
            ->where('tariff', 'Расход')
            ->whereIn('status', Payment::PAID_STATUSES)
            ->whereNull('refund_of_payment_id')
            ->whereNotIn('user_id', $excluded)
            ->where('created_at', '>=', now()->subMonths(6))
            ->sum('amount');

        return [abs($sum) / 6, true];
    }

    private const COVER_PAYPAL = 'откройте PayPal';
}
