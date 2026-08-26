<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\FinanceSnapshot;
use App\Models\Payment;
use App\Models\Teacher;
use App\Models\TeacherPayout;
use App\Models\User;
use App\Services\Payroll\PayrollRateCalculator;
use Illuminate\Support\Carbon;

/**
 * H3532 — прогнозный слой «Год» поверх TeacherWeeklyPayoutCalendarService::grid().
 * READ ONLY по money-таблицам: отпечатки teacher_payouts/payments/users до/после.
 * Пишет только finance_snapshots (ручной PayPal-баланс). Суммы всегда
 * предварительные (рулинг #2): даты твёрдые, деньги растут с оплатами студентов.
 */
final class PayoutForecastService
{
    public const TRIGGER_STAFF = 'staff_schedule';

    public const TRIGGER_CONTRACTOR = 'contractor_fee';

    public function __construct(
        private readonly TeacherWeeklyPayoutCalendarService $weekly,
        private readonly PayrollRateCalculator $rates,
    ) {}

    /**
     * Годовая сетка 52 ISO-недель × все получатели (преподы ∪ штат ∪ подрядчик).
     *
     * @return array{
     *   year: int,
     *   weeks: list<array<string, mixed>>,
     *   tochka: array<string, mixed>,
     *   paypal: array{balance_eur: float|null, entered_at: string|null, source: string},
     *   fx_eur: array{rate: float, source: string},
     *   horizon4: array{rub_need: float, eur_need: float},
     *   money_tables_moved: bool
     * }
     */
    public function yearGrid(int $year): array
    {
        $before = $this->fingerprint();

        $base = $this->weekly->grid($year);
        $fx = $this->fxEur();
        $paypal = $this->paypalBalance();

        $slugByTeacherId = [];
        foreach (Teacher::query()->get(['id', 'name']) as $t) {
            $slug = $this->rates->matchName((string) $t->name);
            if ($slug !== null) {
                $slugByTeacherId[(int) $t->id] = $slug;
            }
        }

        $receipts = $this->trailingReceiptsByTeacher();

        // 1. Преподаватели: даты из недельной сетки (блок 4 / годовщина), суммы — формула.
        foreach ($base['weeks'] as &$week) {
            $rubNeed = 0.0;
            $eurNeed = 0.0;
            foreach ($week['due'] as $i => $due) {
                $tid = (int) ($due['teacher_id'] ?? 0);
                $slug = $slugByTeacherId[$tid] ?? null;
                $on = Carbon::parse((string) $due['due_on']);
                if ($slug === null || ($rcpt = $this->rates->find($slug)) === null) {
                    $week['due'][$i] = $due + [
                        'recipient_kind' => 'teacher',
                        'channel' => 'tochka_maria',
                        'amount_rub_prelim' => round(max(0.0, (float) $due['balance']), 2),
                        'amount_eur_prelim' => null,
                        'npd_note' => null,
                        'formula_note' => '⚠️ таймлайна ставок нет — дефолт к балансу LMS',
                    ];

                    continue;
                }
                $res = $this->rates->netFor($slug, $on, [
                    'receipts_rub' => [$receipts[$tid] ?? 0.0],
                    'fx_rate' => $rcpt['lane'] === 'EUR' ? $fx['rate'] : null,
                ]);
                $eur = null;
                if ($rcpt['lane'] === 'EUR') {
                    $eur = $res['payable_rub'] > 0 ? round($res['payable_rub'] / $fx['rate'], 2) : 0.0;
                    $eurNeed += $eur;
                } else {
                    $rubNeed += max(0.0, $res['payable_rub']);
                }
                $week['due'][$i] = [
                    'teacher_id' => $tid,
                    'name' => $due['name'],
                    'recipient_kind' => 'teacher',
                    'trigger' => $due['trigger'],
                    'due_on' => $due['due_on'],
                    'channel' => $rcpt['channel'],
                    'lane' => $rcpt['lane'],
                    'amount_rub_prelim' => round($res['payable_rub'], 2),
                    'amount_eur_prelim' => $eur,
                    'preliminary' => true,
                    'npd_note' => $res['net_after_npd_rub'] !== null
                        ? sprintf('НПД −%s%%: нетто %s ₽', rtrim(rtrim((string) $res['npd_pct'], '0'), '.'), number_format($res['net_after_npd_rub'], 2, ',', ' '))
                        : null,
                    'formula_note' => implode(' · ', $res['notes']),
                ] + (isset($due['preliminary']) ? [] : []);
            }
            $week['rub_need_forecast'] = round($rubNeed, 2);
            $week['eur_need_forecast'] = round($eurNeed, 2);
            $week['paypal_cover_forecast'] = $this->paypalCover($eurNeed, $paypal);
        }
        unset($week);

        $weeksByIndex = [];
        foreach ($base['weeks'] as $w) {
            $weeksByIndex[(int) $w['iso_week']] = $w;
        }

        // 2. Штат и подрядчик: собственные триггеры по дням месяца.
        foreach ($this->staffAndContractorDues($year, $fx['rate']) as $due) {
            $wk = (int) Carbon::parse($due['due_on'])->isoWeek;
            if (! isset($weeksByIndex[$wk])) {
                continue;
            }
            /** @var array<string, mixed> $wrow */
            $wrow = &$weeksByIndex[$wk];
            $wrow['due'][] = $due;
            if ($due['lane'] === 'EUR') {
                $wrow['eur_need_forecast'] = round((float) $wrow['eur_need_forecast'] + (float) $due['amount_eur_prelim'], 2);
                $wrow['paypal_cover_forecast'] = $this->paypalCover((float) $wrow['eur_need_forecast'], $paypal);
            } else {
                $wrow['rub_need_forecast'] = round((float) $wrow['rub_need_forecast'] + (float) $due['amount_rub_prelim'], 2);
            }
        }
        unset($wrow);

        $weeks = array_values($weeksByIndex);
        usort($weeks, fn ($a, $b) => $a['iso_week'] <=> $b['iso_week']);

        return [
            'year' => $year,
            'weeks' => $weeks,
            'tochka' => $base['tochka'],
            'paypal' => $paypal,
            'fx_eur' => $fx,
            'horizon4' => $this->horizon($weeks, 4),
            'money_tables_moved' => $this->fingerprint() !== $before,
        ];
    }

    /** Ручной ввод баланса PayPal — ЕДИНСТВЕННАЯ запись этого сервиса (finance_snapshots). */
    public function recordPaypalBalance(float $eurMajor, int $userId): FinanceSnapshot
    {
        return FinanceSnapshot::query()->create([
            'type' => FinanceSnapshot::TYPE_PAYPAL_BALANCE,
            'amount_minor' => FinanceSnapshot::toMinor($eurMajor),
            'currency' => 'EUR',
            'entered_at' => now(),
            'user_id' => $userId,
            'note' => 'ручной ввод на календаре выплат (H3532)',
        ]);
    }

    /** @return array{rate: float, source: string} */
    public function fxEur(): array
    {
        $snap = FinanceSnapshot::latestOfType(FinanceSnapshot::TYPE_FX_EUR_RUB);
        if ($snap !== null) {
            return ['rate' => $snap->majorAmount(), 'source' => 'finance_snapshots @ '.$snap->entered_at?->format('d.m.Y')];
        }

        return ['rate' => (float) config('teacher_rates.canon.fx_eur_rub_fallback', 90.1127), 'source' => 'config_fallback (исторический)'];
    }

    /** @return array{balance_eur: float|null, entered_at: string|null, source: string} */
    public function paypalBalance(): array
    {
        $snap = FinanceSnapshot::latestOfType(FinanceSnapshot::TYPE_PAYPAL_BALANCE);
        if ($snap === null) {
            return ['balance_eur' => null, 'entered_at' => null, 'source' => 'none'];
        }

        return [
            'balance_eur' => $snap->majorAmount(),
            'entered_at' => $snap->entered_at?->format('d.m.Y'),
            'source' => 'finance_snapshots',
        ];
    }

    private function paypalCover(float $eurNeed, array $paypal): string
    {
        if ($eurNeed <= 0) {
            return 'n/a';
        }
        if (($paypal['balance_eur'] ?? null) === null) {
            return 'no_data';
        }

        return (float) $paypal['balance_eur'] + 0.0001 >= $eurNeed ? 'enough' : 'short';
    }

    /**
     * Дью-строки штата (ритм траншами/днём месяца) и подрядчика (ежемесячный fee).
     *
     * @return list<array<string, mixed>>
     */
    private function staffAndContractorDues(int $year, float $fx): array
    {
        $out = [];
        $cfg = (array) config('teacher_rates');
        foreach (['staff', 'contractors'] as $section) {
            foreach ((array) ($cfg[$section] ?? []) as $slug => $row) {
                $rcpt = $this->rates->find((string) $slug);
                if ($rcpt === null) {
                    continue;
                }
                $dates = [];
                for ($m = 1; $m <= 12; $m++) {
                    $first = Carbon::create($year, $m, 1)->startOfDay();
                    if ($rcpt['tranches'] !== []) {
                        foreach ($rcpt['tranches'] as $tranche) {
                            $day = min((int) $tranche['day'], $first->daysInMonth);
                            $dates[] = [$first->copy()->day($day), (float) $tranche['amount_rub']];
                        }
                    } elseif ($rcpt['pay_day'] !== null) {
                        $day = min($rcpt['pay_day'], $first->daysInMonth);
                        $dates[] = [$first->copy()->day($day), null];
                    } else {
                        $dates[] = [$first->copy()->day(min(15, $first->daysInMonth)), null];
                    }
                }
                foreach ($dates as [$on, $trancheRub]) {
                    if ((int) $on->isoWeekYear !== $year) {
                        continue;
                    }
                    if ($section === 'staff') {
                        $amountRub = round($trancheRub ?? (float) ($rcpt['monthly_rub'] ?? 0.0), 2);
                        $note = $trancheRub !== null
                            ? 'транш штатного ритма (~30 000 ₽/мес: 18+12)'
                            : sprintf('штат: %.2f ₽/мес + премии отдельными строками', (float) ($rcpt['monthly_rub'] ?? 0.0));
                        $out[] = $this->auxDue($rcpt, self::TRIGGER_STAFF, $on, $amountRub, null, $note);
                    } else {
                        $min = (float) $rcpt['fee_eur_min'];
                        $max = (float) $rcpt['fee_eur_max'];
                        $midEur = round(($min + $max) / 2.0, 2);
                        $out[] = $this->auxDue($rcpt, self::TRIGGER_CONTRACTOR, $on, round($midEur * $fx, 2), $midEur, sprintf('подрядчик €%s–%s/мес (середина)', $min, $max));
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function auxDue(array $rcpt, string $trigger, Carbon $on, float $amountRub, ?float $amountEur, string $note): array
    {
        return [
            'teacher_id' => null,
            'name' => $rcpt['name'],
            'recipient_kind' => $rcpt['kind'],
            'trigger' => $trigger,
            'due_on' => $on->toDateString(),
            'channel' => $rcpt['channel'],
            'lane' => $rcpt['lane'],
            'amount_rub_prelim' => $amountRub,
            'amount_eur_prelim' => $amountEur,
            'preliminary' => true,
            'npd_note' => null,
            'formula_note' => $note,
        ];
    }

    /** @param list<array<string, mixed>> $weeks */
    private function horizon(array $weeks, int $count): array
    {
        $today = now()->startOfDay();
        $rub = 0.0;
        $eur = 0.0;
        $taken = 0;
        foreach ($weeks as $w) {
            if (Carbon::parse((string) $w['end'])->endOfDay()->lt($today)) {
                continue;
            }
            $rub += (float) ($w['rub_need_forecast'] ?? 0.0);
            $eur += (float) ($w['eur_need_forecast'] ?? 0.0);
            if (++$taken >= $count) {
                break;
            }
        }

        return ['rub_need' => round($rub, 2), 'eur_need' => round($eur, 2)];
    }

    /** Поступления на курсах преподавателя за 30 дней — база предварительной суммы. */
    private function trailingReceiptsByTeacher(): array
    {
        $out = [];
        foreach (Teacher::query()->orderBy('id')->get() as $teacher) {
            $courseIds = $teacher->allTaughtCourses()->modelKeys();
            if ($courseIds === []) {
                continue;
            }
            $sum = Payment::query()
                ->whereIn('course_id', $courseIds)
                ->paid()
                ->real()
                ->schoolReceived()
                ->whereNotIn('tariff', TeacherSalaryService::NON_REVENUE_TARIFFS)
                ->where('amount', '>', 0)
                ->where('created_at', '>=', now()->subDays(30))
                ->sum('amount');
            if ((float) $sum > 0) {
                $out[(int) $teacher->id] = (float) $sum;
            }
        }

        return $out;
    }

    /**
     * Отпечаток money-таблиц: счётчики до/после должны совпасть.
     *
     * @return array{teacher_payouts: int, payments: int, users: int}
     */
    public function fingerprint(): array
    {
        return [
            'teacher_payouts' => TeacherPayout::query()->count(),
            'payments' => Payment::query()->count(),
            'users' => User::query()->count(),
        ];
    }
}
