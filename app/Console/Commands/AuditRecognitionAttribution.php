<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Payment;
use App\Services\RevenueRecognitionService;
use App\Services\TeacherSalaryService;
use Illuminate\Console\Command;

/**
 * H3951 — перепись АТРИБУЦИИ месяца признания: чем именно признана каждая строка
 * и что изменится, если включить сторож штампованного прогона.
 *
 * Команда СТРОГО ЧИТАЮЩАЯ. Она ничего не пишет ни в `payments`, ни в
 * `teacher_payouts`, ни в `salary_recognition_month`: её продукт — число, по
 * которому решает человек, а не проводка.
 *
 * Три секции:
 *   1. перепись механизмов по всей популяции платежей (column / blocks /
 *      created / stamped) — сколько строк и на какую сумму признано каждым;
 *   2. поимённо строки, у которых НАБОР МЕСЯЦЕВ меняется при включённом флаге,
 *      с курсом, преподавателем и месяцами «было → стало»;
 *   3. ЗП по преподавателям за затронутые месяцы: до и после, с дельтой.
 *
 * Секция 3 гоняет `summaryForAll` на СВЕЖЕМ экземпляре сервиса под каждым
 * состоянием флага: сервис мемоизирует сводку, и переключить конфиг на уже
 * прогретом объекте значило бы сравнить два одинаковых ответа из кэша.
 */
class AuditRecognitionAttribution extends Command
{
    protected $signature = 'recognition:attribution-audit
        {--months= : Ограничить секцию 3 месяцами (Y-m через запятую); по умолчанию — все затронутые}
        {--limit=50 : Сколько изменившихся строк печатать поимённо}
        {--json : Выдать машинный JSON вместо таблиц}';

    protected $description = 'H3951: чем признан каждый платёж и что изменит сторож штампованного прогона (только чтение)';

    private const FLAG = 'revenue.recognition_stamped_block_run_guard';

    public function handle(): int
    {
        $off = new RevenueRecognitionService;
        $on = new RevenueRecognitionService;

        $census = [];
        $stampedRows = [];
        $changed = [];
        $notRevenue = 0;

        Payment::query()
            ->with('course:id,title')
            ->orderBy('id')
            ->chunkById(500, function ($payments) use ($off, $on, &$census, &$stampedRows, &$changed, &$notRevenue) {
                foreach ($payments as $payment) {
                    if (! $off->isRevenuePayment($payment)) {
                        $notRevenue++;

                        continue;
                    }

                    $before = $off->attributionForPayment($payment, false);
                    $after = $on->attributionForPayment($payment, true);

                    $key = $before['mechanism'].($before['stamped'] ? ' [ШТАМП]' : '');
                    $census[$key] ??= ['rows' => 0, 'amount' => 0.0];
                    $census[$key]['rows']++;
                    $census[$key]['amount'] += (float) $payment->amount;

                    if (! $before['stamped']) {
                        continue;
                    }

                    $row = [
                        'payment_id' => (int) $payment->id,
                        'course_id' => $payment->course_id ? (int) $payment->course_id : null,
                        'course' => (string) ($payment->course?->title ?? '—'),
                        'amount' => (float) $payment->amount,
                        'created_month' => $payment->created_at?->format('Y-m'),
                        'months_before' => array_keys($before['shares']),
                        'months_after' => array_keys($after['shares']),
                    ];
                    $stampedRows[] = $row;

                    if ($row['months_before'] !== $row['months_after']) {
                        $changed[] = $row;
                    }
                }
            });

        $months = $this->affectedMonths($changed);
        $payroll = $months === [] ? [] : $this->payrollDelta($months);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'flag' => self::FLAG,
                'flag_default' => (bool) config(self::FLAG),
                'non_revenue_rows_skipped' => $notRevenue,
                'census' => $census,
                'stamped_rows' => count($stampedRows),
                'changed_rows' => $changed,
                'affected_months' => $months,
                'payroll' => $payroll,
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->renderCensus($census, $notRevenue, count($stampedRows), count($changed));
        $this->renderChanged($changed);
        $this->renderPayroll($months, $payroll);

        return self::SUCCESS;
    }

    /**
     * Месяцы, по которым имеет смысл считать ЗП: объединение «было» и «стало» по
     * изменившимся строкам. `--months` сужает список вручную.
     *
     * @param  list<array<string, mixed>>  $changed
     * @return list<string>
     */
    private function affectedMonths(array $changed): array
    {
        if ($manual = trim((string) $this->option('months'))) {
            return array_values(array_filter(array_map('trim', explode(',', $manual))));
        }

        $months = [];
        foreach ($changed as $row) {
            foreach ([...$row['months_before'], ...$row['months_after']] as $m) {
                $months[$m] = true;
            }
        }
        ksort($months);

        return array_keys($months);
    }

    /**
     * ЗП по преподавателям за каждый затронутый месяц при флаге OFF и ON.
     * Каждый прогон — свой экземпляр сервиса (мемоизация summaryForAll).
     *
     * @param  list<string>  $months
     * @return array<string, list<array<string, mixed>>>
     */
    private function payrollDelta(array $months): array
    {
        $original = config(self::FLAG);
        $out = [];

        try {
            foreach ($months as $month) {
                config([self::FLAG => false]);
                $before = (new TeacherSalaryService)->summaryForAll($month);

                config([self::FLAG => true]);
                $after = (new TeacherSalaryService)->summaryForAll($month);

                $rows = [];
                foreach ($before as $teacherId => $row) {
                    $earnedBefore = (float) $row['earned_period'];
                    $earnedAfter = (float) ($after[$teacherId]['earned_period'] ?? 0.0);
                    if (abs($earnedAfter - $earnedBefore) < 0.005) {
                        continue;
                    }
                    $rows[] = [
                        'teacher_id' => (int) $teacherId,
                        'name' => (string) $row['name'],
                        'earned_before' => $earnedBefore,
                        'earned_after' => $earnedAfter,
                        'delta' => $earnedAfter - $earnedBefore,
                    ];
                }
                $out[$month] = $rows;
            }
        } finally {
            config([self::FLAG => $original]);
        }

        return $out;
    }

    /**
     * @param  array<string, array{rows: int, amount: float}>  $census
     */
    private function renderCensus(array $census, int $notRevenue, int $stamped, int $changed): void
    {
        $this->info('1. Перепись механизмов признания (популяция: выручкообразующие платежи)');
        uasort($census, fn ($a, $b) => $b['rows'] <=> $a['rows']);
        $this->table(
            ['механизм (флаг OFF)', 'строк', 'сумма ₽'],
            array_map(
                fn ($k, $v) => [$k, $v['rows'], number_format($v['amount'], 2, '.', ' ')],
                array_keys($census),
                array_values($census),
            ),
        );
        $this->line("  не образуют выручку и в перепись не входят: {$notRevenue} строк");
        $this->line("  помечено штампованным прогоном: {$stamped}; из них меняют месяцы: {$changed}");
        $this->line('  флаг '.self::FLAG.' сейчас: '.(config(self::FLAG) ? 'ON' : 'OFF (дефолт)'));
        $this->newLine();
    }

    /**
     * @param  list<array<string, mixed>>  $changed
     */
    private function renderChanged(array $changed): void
    {
        $this->info('2. Строки, у которых меняется набор месяцев');
        if ($changed === []) {
            $this->line('  нет ни одной.');
            $this->newLine();

            return;
        }

        $limit = max(1, (int) $this->option('limit'));
        $this->table(
            ['payment', 'курс', 'сумма ₽', 'месяцы БЫЛО', 'месяцы СТАЛО'],
            array_map(fn ($r) => [
                $r['payment_id'],
                mb_strimwidth($r['course'], 0, 34, '…'),
                number_format($r['amount'], 2, '.', ' '),
                implode(', ', $r['months_before']),
                implode(', ', $r['months_after']),
            ], array_slice($changed, 0, $limit)),
        );
        if (count($changed) > $limit) {
            $this->line('  … и ещё '.(count($changed) - $limit).' строк (см. --limit / --json)');
        }
        $this->newLine();
    }

    /**
     * @param  list<string>  $months
     * @param  array<string, list<array<string, mixed>>>  $payroll
     */
    private function renderPayroll(array $months, array $payroll): void
    {
        $this->info('3. ЗП по преподавателям: до и после (затронутые месяцы)');
        if ($months === []) {
            $this->line('  затронутых месяцев нет — считать нечего.');

            return;
        }

        foreach ($months as $month) {
            $rows = $payroll[$month] ?? [];
            $this->line("  {$month}:");
            if ($rows === []) {
                $this->line('    ни у одного преподавателя начисление не изменилось.');

                continue;
            }
            $this->table(
                ['преподаватель', 'было ₽', 'стало ₽', 'дельта ₽'],
                array_map(fn ($r) => [
                    $r['name'],
                    number_format($r['earned_before'], 2, '.', ' '),
                    number_format($r['earned_after'], 2, '.', ' '),
                    number_format($r['delta'], 2, '.', ' '),
                ], $rows),
            );
        }
        $this->newLine();
        $this->line('  Ни одна строка teacher_payouts этой командой не создана и не изменена.');
    }
}
