<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\FinanceCockpitReport;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * H5554 gap [1]: единый тройной отчёт «Нескучных финансов» по циклам выплат.
 * Один вывод = один цикл (месяц) с секциями ДДС (касса) и ОПиУ (начисление),
 * плюс снимок Баланса на сейчас (обязательства, авансы). Сборочный слой поверх
 * FinanceCockpitReport — данных не создаёт и денежную семантику не трогает.
 *
 * Ограничение среза: Баланс у канонического ядра точечный (на сейчас), поэтому
 * он выводится одним снимком, а не внутри каждого цикла; строки «неразобранные
 * поступления» (маппинг выписки Точки) в этом срезе нет — это отдельный кусок.
 */
final class PayrollTripleReportCommand extends Command
{
    protected $signature = 'payroll:triple-report
        {--periods= : Comma-separated YYYY-MM payout cycles; defaults to the 3 most recent months}
        {--format= : text (default) or json}';

    protected $description = 'Per payout cycle: DDS (kassa) + OPiU (accrual) sections and a Balance obligations snapshot (read-only)';

    public function handle(FinanceCockpitReport $report): int
    {
        $periods = $this->periods();
        if ($periods === null) {
            $this->error('Bad --periods: expected comma-separated YYYY-MM');

            return self::FAILURE;
        }

        $format = strtolower((string) ($this->option('format') ?: 'text'));
        if (! in_array($format, ['text', 'json'], true)) {
            $this->error('Bad --format: expected text or json');

            return self::FAILURE;
        }

        $cycles = [];
        foreach ($periods as $period) {
            $dds = $report->dds($period);
            // H5554 gap [3], MG ruling 01-10-2026: фонд ЗП = 12% поступлений
            // цикла (не остаток кассы). Ставка конфигурируема, даты вступления
            // в силу команда не решает — только рендерит строку.
            $fundRate = (float) config('payroll_readiness.salary_fund_rate', 0.12);
            $cycles[] = [
                'period' => $period,
                'dds' => $dds,
                'salary_fund' => ['rate' => $fundRate, 'amount' => round($dds['inflow'] * $fundRate, 2)],
                'opiu_accrual' => $report->opiuAccrual($period),
                'deferred_revenue' => $report->deferredRevenue($period),
            ];
        }

        $payload = [
            'schema' => 'payroll-triple-report/v1',
            'generated_at' => now()->toIso8601String(),
            'cycles' => $cycles,
            'balance_snapshot' => $report->balance(),
        ];

        if ($format === 'json') {
            $this->line(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        foreach ($cycles as $cycle) {
            $this->info('=== Cycle '.$cycle['period'].' — DDS (kassa) ===');
            $dds = $cycle['dds'];
            $this->table(['line', 'RUB'], [
                ['inflow', $this->money($dds['inflow'])],
                ['salary_out', $this->money($dds['salaryOut'])],
                ['refund_out', $this->money($dds['refundOut'])],
                ['opex_out', $this->money($dds['opexOut'])],
                ['net', $this->money($dds['net'])],
            ]);

            $fund = $cycle['salary_fund'];
            $this->line('salary fund (MG 01-10: '.$fund['rate'] * 100
                .'% of receipts): '.$this->money($fund['amount']));

            $this->info('=== Cycle '.$cycle['period'].' — OPiU (accrual) ===');
            $opiu = $cycle['opiu_accrual'];
            $this->table(['line', 'RUB'], [
                ['revenue (accrual)', $this->money($opiu['revenue'])],
                ['salary_cogs', $this->money($opiu['salaryCogs'])],
                ['acquiring', $this->money($opiu['acquiring'])],
                ['gross_profit', $this->money($opiu['grossProfit'])],
                ['marketing', $this->money($opiu['marketing'])],
                ['admin_total', $this->money($opiu['adminTotal'])],
                ['ebitda', $this->money($opiu['ebitda'])],
            ]);

            $deferred = $cycle['deferred_revenue'];
            $this->line('deferred revenue: cash '.$this->money($deferred['cashReceived'])
                .' / recognized '.$this->money($deferred['recognized'])
                .' / deferred '.$this->money($deferred['deferred']));
            $this->line('');
        }

        $this->info('=== Balance snapshot (as of now) ===');
        $balance = $payload['balance_snapshot'];
        $this->table(['line', 'RUB'], [
            ['remaining_obligation (teachers payable)', $this->money($balance['teacherPayable'])],
            ['outstanding_advances', $this->money($balance['advances'])],
            ['student_debt', $this->money($balance['debt'])],
            ['unconsumed_deposits', $this->money($balance['deposits'])],
            ['prana_liability', $this->money($balance['pranaLiability'])],
            ['referral_credit', $this->money($balance['referral'])],
        ]);

        return self::SUCCESS;
    }

    /** @return list<string>|null */
    private function periods(): ?array
    {
        $raw = trim((string) ($this->option('periods') ?: ''));
        if ($raw !== '') {
            $periods = [];
            foreach (explode(',', $raw) as $piece) {
                $period = trim($piece);
                if (! preg_match('/^\d{4}-\d{2}$/', $period) || ! checkdate((int) substr($period, 5, 2), 1, (int) substr($period, 0, 4))) {
                    return null;
                }
                $periods[] = $period;
            }

            return $periods;
        }

        // По умолчанию — три последних завершённых+текущий цикла, старшими вперёд.
        $periods = [];
        $cursor = Carbon::now()->startOfMonth();
        for ($i = 0; $i < 3; $i++) {
            $periods[] = $cursor->format('Y-m');
            $cursor->subMonthNoOverflow();
        }

        return array_reverse($periods);
    }

    private function money(float $value): string
    {
        return number_format($value, 2, '.', ' ').' ₽';
    }
}
