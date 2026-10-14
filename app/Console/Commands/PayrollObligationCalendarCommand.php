<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Payment;
use App\Models\Teacher;
use App\Models\TeacherPayout;
use App\Models\User;
use App\Services\Payroll\PayrollReadinessService;
use App\Support\FinanceCockpitReport;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * H6706 — H5554 gap [4], MG ruling 01-10-2026: датированный календарь
 * обязательств и погашений. Остаток владельца на срезе 29-09-2026 (864 385 ₽)
 * делится 70 % burn / 30 % дивидендов; burn гасит открытые обязательства
 * старейшие-first (включая сброшенный базлайн Гасунса с 01-08-2026), каденс —
 * цикл выплат, без фиксированной даты месяца.
 *
 * Только чтение: обязательства перечисляются из канонических источников
 * (payroll-readiness строки — тот же remaining_obligation, что и в
 * payroll:triple-report; непогашенные авансы; непотраченные депозиты; прана и
 * реферальный кредит), пулы берутся из config/payroll_readiness.php. Правление
 * НЕ фиксирует формулу мощности на цикл — дефолт: доля × чистый ДДС цикла
 * (свободная касса), пол 0; пулы ограничивают итоги правлеными суммами.
 */
final class PayrollObligationCalendarCommand extends Command
{
    /** Схедул не бесконечен: столько циклов рендерится максимум. */
    private const MAX_CYCLES = 36;

    protected $signature = 'payroll:obligation-calendar
        {--cycles=12 : Max payout cycles to render in the burn schedule}
        {--format= : text (default) or json}';

    protected $description = 'Dated obligation list (teachers, advances, deposits, owner pools) + per-cycle 70/30 burn schedule, oldest-first (read-only)';

    public function handle(PayrollReadinessService $readiness, FinanceCockpitReport $report): int
    {
        $format = strtolower((string) ($this->option('format') ?: 'text'));
        if (! in_array($format, ['text', 'json'], true)) {
            $this->error('Bad --format: expected text or json');

            return self::FAILURE;
        }
        $maxCycles = max(1, min(self::MAX_CYCLES, (int) $this->option('cycles')));

        $cutoff = now()->startOfDay();
        $readinessRows = $readiness->build($cutoff)['teachers'];

        $obligations = $this->obligations($readiness, $readinessRows, $cutoff);
        $schedule = $this->burnSchedule($report, $obligations, $maxCycles);

        $payload = [
            'schema' => 'payroll-obligation-calendar/v1',
            'generated_at' => now()->toIso8601String(),
            'ruling' => $this->ruling(),
            'obligations' => $obligations,
            'burn_schedule' => $schedule['cycles'],
            'pools_after_schedule' => $schedule['pools'],
        ];

        if ($format === 'json') {
            $this->line(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $ruling = $payload['ruling'];
        $this->info('=== Owner remainder pools (MG ruling 01-10-2026, cut '.$ruling['cut_as_of'].') ===');
        $this->table(['pool', 'RUB'], [
            ['owner remainder (cut)', $this->money($ruling['cut_amount'])],
            ['burn pool ('.$ruling['burn_share_pct'].'%)', $this->money($ruling['burn_pool'])],
            ['dividend pool ('.$ruling['dividend_share_pct'].'%)', $this->money($ruling['dividend_pool'])],
        ]);
        $this->line('burn closes obligations oldest-first (incl. reset Gasuns baseline from 01-08-2026); cadence = payout cycle, no fixed monthly date');
        $this->line('per-cycle capacity (not fixed by the ruling): '.$ruling['burn_share_pct'].'% × cycle DDS net ('.$ruling['burn_capacity_basis'].')');
        $this->line('');

        $this->info('=== Open obligations (dated) ===');
        $rows = [];
        foreach ($obligations as $o) {
            $rows[] = [
                $o['kind'],
                (string) ($o['ref'] ?? ''),
                (string) ($o['name'] ?? ''),
                $this->money((float) ($o['amount_rub'] ?? 0)),
                (string) ($o['since'] ?? '—'),
                (string) ($o['note'] ?? ''),
            ];
        }
        $this->table(['kind', 'ref', 'name', 'amount', 'since', 'note'], $rows);
        $this->line('');

        $this->info('=== Burn schedule (70% per cycle, oldest-first) ===');
        foreach ($schedule['cycles'] as $cycle) {
            $this->line('--- Cycle '.$cycle['period'].' — DDS net '.$this->money($cycle['dds_net'])
                .' → burn capacity '.$this->money($cycle['burn_capacity']).' / dividends capacity '.$this->money($cycle['dividend_capacity']).' ---');
            if ($cycle['allocations'] === []) {
                $this->line('  (no burn allocations in this cycle)');
            }
            foreach ($cycle['allocations'] as $a) {
                $this->line('  closes '.$a['kind'].' '.$a['ref'].' '.$this->money($a['amount'])
                    .' (obligation closed: '.($a['obligation_closed'] ? 'yes' : 'partially').')');
            }
            if ($cycle['dividends'] > 0.0) {
                $this->line('  dividends: '.$this->money($cycle['dividends']));
            }
        }
        $this->line('');
        $this->line('pools after schedule: burn '.$this->money($schedule['pools']['burn'])
            .' / dividends '.$this->money($schedule['pools']['dividends']));

        return self::SUCCESS;
    }

    /**
     * Каждое открытое обязательство с датой. Долг учеников — дебиторка, а не
     * обязательство школы, поэтому не здесь (он в DebtorsReport/календаре
     * кассы); авансы — дебиторка-зачёт, рендерятся с kind=receivable_advance.
     *
     * @param  array<int, array<string, mixed>>  $readinessRows
     * @return list<array<string, mixed>>
     */
    private function obligations(PayrollReadinessService $readiness, array $readinessRows, Carbon $cutoff): array
    {
        $out = [];

        // Преподаватели: тот же remaining_obligation, что в readiness-кабинете
        // (окна с teacher_since_overrides = сброшенный базлайн Гасунса).
        foreach ($readinessRows as $row) {
            $rub = (float) ($row['payable_rub'] ?? 0);
            $eur = $row['payable_eur'] ?? null;
            if ($rub <= 0 && ($eur === null || (float) $eur <= 0)) {
                continue;
            }
            $out[] = [
                'kind' => 'teacher_obligation',
                'ref' => 'teacher_'.$row['teacher_id'],
                'name' => (string) $row['name'],
                'amount_rub' => Money::round($rub),
                'amount_eur' => $eur !== null ? Money::round((float) $eur) : null,
                'since' => $readiness->sinceForTeacher(Teacher::query()->findOrFail($row['teacher_id']), $cutoff)?->toDateString(),
                'due_on' => (string) ($row['due_on'] ?? ''),
                'channel' => (string) ($row['channel'] ?? ''),
                'disposition' => (string) ($row['disposition'] ?? ''),
                'order_by' => 'due_on',
                'order_date' => (string) ($row['due_on'] ?? '9999-12-31'),
                'note' => $eur !== null && (float) $eur > 0
                    ? 'EUR lane: listed, not allocated by the RUB burn schedule'
                    : ($row['disposition'] === 'held' ? 'held by evidence/holds — amount stands, release is readiness-gated' : ''),
            ];
        }

        // Непогашенные авансы: деньги школы у преподавателя (дебиторка-зачёт).
        $advances = TeacherPayout::query()
            ->unsettledAdvances()
            ->whereNotNull('paid_at')
            ->orderBy('paid_at')
            ->get(['teacher_id', 'amount', 'settled_amount', 'paid_at']);
        foreach ($advances as $advance) {
            $remaining = Money::round((float) $advance->amount - (float) ($advance->settled_amount ?? 0));
            if ($remaining <= 0) {
                continue;
            }
            $out[] = [
                'kind' => 'receivable_advance',
                'ref' => 'payout_'.$advance->id,
                'name' => 'teacher_'.$advance->teacher_id,
                'amount_rub' => $remaining,
                'since' => $advance->paid_at?->toDateString(),
                'order_by' => 'n/a — receivable, offsets future obligations',
                'order_date' => null,
                'note' => 'asset: offset against future salary, not burned',
            ];
        }

        // Непотраченные депозиты/брони — пассив; зачтение по датам не ведётся,
        // поэтому дата = создание брони (честная пометка в note).
        foreach (Payment::query()->unconsumedDeposits()->orderBy('created_at')->get() as $deposit) {
            $remaining = Money::round((float) $deposit->amount - (float) ($deposit->consumed_amount ?? 0));
            if ($remaining <= 0) {
                continue;
            }
            $out[] = [
                'kind' => 'deposit_liability',
                'ref' => 'payment_'.$deposit->id,
                'name' => 'user_'.$deposit->user_id,
                'amount_rub' => $remaining,
                'since' => $deposit->created_at?->toDateString(),
                'order_by' => 'created_at',
                'order_date' => $deposit->created_at?->toDateString() ?? '9999-12-31',
                'note' => 'consumption events are not dated in the ledger — since = deposit creation',
            ];
        }

        // Прана и реферальный кредит — обязательства без даты в каноне.
        $prana = Money::round((float) User::query()->sum('prana_balance') / 10);
        if ($prana > 0) {
            $out[] = [
                'kind' => 'prana_liability',
                'ref' => 'users_prana',
                'name' => 'all users',
                'amount_rub' => $prana,
                'since' => null,
                'order_by' => 'undated',
                'order_date' => null,
                'note' => 'upper bound: full prana balance at 10 prana = 1 ₽',
            ];
        }
        $referral = Money::round((float) User::query()->sum('referral_credit'));
        if ($referral > 0) {
            $out[] = [
                'kind' => 'referral_credit',
                'ref' => 'users_referral',
                'name' => 'all referrers',
                'amount_rub' => $referral,
                'since' => null,
                'order_by' => 'undated',
                'order_date' => null,
                'note' => '',
            ];
        }

        return $out;
    }

    /**
     * Пос cycle-схедул burn'а: мощность = доля правления × чистый ДДС цикла
     * (пол 0), обязательства гасятся старейшие-first; дивиденды — вторая доля
     * той же мощности из своего пула. Пулы ограничивают итоги правлеными
     * суммами на срезе.
     *
     * @param  list<array<string, mixed>>  $obligations
     * @return array{cycles: list<array<string, mixed>>, pools: array{burn: float, dividends: float}}
     */
    private function burnSchedule(FinanceCockpitReport $report, array $obligations, int $maxCycles): array
    {
        $ruling = $this->ruling();
        $burnRemaining = $ruling['burn_pool'];
        $dividendsRemaining = $ruling['dividend_pool'];

        // Burnable: датированные обязательства старейшие-first (due_on, потом
        // created_at), недатированные — в хвост. Авансы (дебиторка) не горят.
        $burnable = array_values(array_filter(
            $obligations,
            fn (array $o): bool => in_array($o['kind'], ['teacher_obligation', 'deposit_liability'], true)
                && $o['order_date'] !== null,
        ));
        usort($burnable, fn (array $a, array $b): int => [$a['order_date'], $a['kind'], $a['ref']] <=> [$b['order_date'], $b['kind'], $b['ref']]);
        $undated = array_values(array_filter(
            $obligations,
            fn (array $o): bool => in_array($o['kind'], ['teacher_obligation', 'deposit_liability'], true)
                && $o['order_date'] === null,
        ));
        $queue = array_merge($burnable, $undated);
        // RUB-пул не гасит EUR-строки: они вне RUB-схедула (пометка в note).
        $queue = array_values(array_filter($queue, fn (array $o): bool => (float) ($o['amount_rub'] ?? 0) > 0));

        $cycles = [];
        $cursor = now()->startOfMonth();
        for ($i = 0; $i < $maxCycles; $i++) {
            $period = $cursor->format('Y-m');
            $net = (float) $report->dds($period)['net'];
            $burnCapacity = round($ruling['burn_share'] * max(0.0, $net), 2);
            $dividendCapacity = round($ruling['dividend_share'] * max(0.0, $net), 2);

            $allocations = [];
            $capacity = min($burnCapacity, $burnRemaining);
            foreach ($queue as $index => $o) {
                if ($capacity <= 0.0) {
                    break;
                }
                $owed = (float) $o['amount_rub'];
                $applied = Money::round(min($owed, $capacity));
                $remainingAfter = Money::round($owed - $applied);
                $closed = $remainingAfter <= 0.0;
                $queue[$index]['amount_rub'] = $remainingAfter;
                $capacity = Money::round($capacity - $applied);
                $burnRemaining = Money::round($burnRemaining - $applied);
                if ($closed) {
                    unset($queue[$index]);
                }
                $allocations[] = [
                    'kind' => $o['kind'],
                    'ref' => (string) $o['ref'],
                    'amount' => $applied,
                    'obligation_closed' => $closed,
                ];
            }
            $queue = array_values($queue);

            $dividends = Money::round(min($dividendCapacity, $dividendsRemaining));
            $dividendsRemaining = Money::round($dividendsRemaining - $dividends);

            $cycles[] = [
                'period' => $period,
                'dds_net' => Money::round($net),
                'burn_capacity' => $burnCapacity,
                'dividend_capacity' => $dividendCapacity,
                'allocations' => $allocations,
                'dividends' => $dividends,
            ];

            if ($burnRemaining <= 0.0 && $dividendsRemaining <= 0.0) {
                break;
            }
            $cursor->addMonthNoOverflow();
        }

        return [
            'cycles' => $cycles,
            'pools' => ['burn' => max(0.0, $burnRemaining), 'dividends' => max(0.0, $dividendsRemaining)],
        ];
    }

    /**
     * @return array{cut_amount:float, cut_as_of:string, burn_share:float,
     *     dividend_share:float, burn_share_pct:string, dividend_share_pct:string,
     *     burn_pool:float, dividend_pool:float, burn_capacity_basis:string}
     */
    private function ruling(): array
    {
        $cut = (array) config('payroll_readiness.owner_remainder_cut', []);
        $amount = (float) ($cut['amount'] ?? 0);
        $burnShare = (float) ($cut['burn_share'] ?? 0.7);
        $dividendShare = (float) ($cut['dividend_share'] ?? 0.3);

        return [
            'cut_amount' => $amount,
            'cut_as_of' => (string) ($cut['as_of'] ?? ''),
            'burn_share' => $burnShare,
            'dividend_share' => $dividendShare,
            'burn_share_pct' => round($burnShare * 100, 1),
            'dividend_share_pct' => round($dividendShare * 100, 1),
            'burn_pool' => Money::round($amount * $burnShare),
            'dividend_pool' => Money::round($amount * $dividendShare),
            'burn_capacity_basis' => (string) ($cut['burn_capacity_basis'] ?? 'dds_net'),
        ];
    }

    private function money(float $value): string
    {
        return number_format($value, 2, '.', ' ').' ₽';
    }
}
