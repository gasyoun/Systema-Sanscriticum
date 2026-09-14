<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Payment;
use App\Models\TeacherPayout;
use App\Models\User;
use App\Services\Payroll\PayrollRateCalculator;
use Illuminate\Console\Command;

/**
 * H3532/W3 — бэктест формул «на руки» против фикстур ожиданий (расчёты Марины,
 * якоря реестра ставок H3531). Гейт ≤1 % расхождения на получателя×месяц.
 * Read-only: ни teacher_payouts, ни payments, ни users не пишутся —
 * отпечатки счётчиков до/после входят в вывод.
 */
class PayrollPayoutBacktestCommand extends Command
{
    protected $signature = 'payroll:payout-backtest
        {--from=2026-02 : стартовое окно YYYY-MM (включительно)}
        {--to=2026-07 : конец окна YYYY-MM (включительно)}
        {--json : machine-readable summary}';

    protected $description = 'Replay payout formulas Feb-Jul 2026 against Marina-expected fixtures; gate <=1% deviation';

    private const GATE_PCT = 1.0;

    public function handle(PayrollRateCalculator $calc): int
    {
        $from = (string) ($this->option('from') ?: '2026-02');
        $to = (string) ($this->option('to') ?: '2026-07');

        $beforeP = TeacherPayout::query()->count();
        $beforePay = Payment::query()->count();
        $beforeU = User::query()->count();

        $dir = base_path('tests/fixtures/payroll_backtest');
        $files = is_dir($dir) ? glob($dir.'/*.json') ?: [] : [];
        sort($files);

        if ($files === []) {
            $this->error("no fixtures found in {$dir}");

            return self::FAILURE;
        }

        $violations = [];
        $checked = 0;
        $maxByRecipient = [];
        $fixtureCount = 0;

        foreach ($files as $file) {
            $fx = json_decode((string) file_get_contents($file), true);
            if (! is_array($fx) || ! isset($fx['recipient'], $fx['months'])) {
                continue;
            }
            $fixtureCount++;
            $slug = (string) $fx['recipient'];
            foreach ((array) $fx['months'] as $month) {
                $m = (string) ($month['month'] ?? '');
                if ($m < $from || $m > $to) {
                    continue;
                }
                $checked++;
                $inputs = (array) ($month['inputs'] ?? []);
                $expected = (array) ($month['expected'] ?? []);
                $res = $calc->netFor($slug, (string) ($month['on'] ?? ($m.'-15')), $inputs);
                $anchor = (string) ($month['anchor'] ?? '—');

                if (isset($expected['range_eur'])) {
                    [$lo, $hi] = array_map(floatval(...), (array) $expected['range_eur']);
                    $mid = $calc->find($slug) !== null && $res['eur_range'] !== null
                        ? ($res['eur_range']['min'] + $res['eur_range']['max']) / 2.0
                        : null;
                    $ok = $mid !== null && $mid >= $lo && $mid <= $hi;
                    $dev = 0.0;
                    if (! $ok) {
                        $violations[] = $this->violation($file, $fx, $m, $anchor, "range €{$lo}–{$hi}", $mid === null ? 'n/a' : "€{$mid}", 100.0);
                    }

                    continue;
                }

                if (isset($expected['payable_eur'])) {
                    $exp = (float) $expected['payable_eur'];
                    $got = $res['payable_eur'];
                    if ($got === null || $exp <= 0) {
                        $violations[] = $this->violation($file, $fx, $m, $anchor, $exp, $got ?? 'null', 100.0);

                        continue;
                    }
                    $dev = abs($got - $exp) / $exp * 100.0;
                } else {
                    $exp = (float) ($expected['payable_rub'] ?? 0.0);
                    $got = $res['payable_rub'];
                    if ($exp <= 0) {
                        $violations[] = $this->violation($file, $fx, $m, $anchor, $exp, $got, 100.0);

                        continue;
                    }
                    $dev = abs($got - $exp) / $exp * 100.0;
                }

                $maxByRecipient[$slug] = max($maxByRecipient[$slug] ?? 0.0, round($dev, 4));
                if ($dev > self::GATE_PCT) {
                    $violations[] = $this->violation($file, $fx, $m, $anchor, $exp, $got, round($dev, 4));
                }
            }
        }

        $afterP = TeacherPayout::query()->count();
        $afterPay = Payment::query()->count();
        $afterU = User::query()->count();
        $moved = $beforeP !== $afterP || $beforePay !== $afterPay || $beforeU !== $afterU;

        ksort($maxByRecipient);
        $summary = [
            'window' => ['from' => $from, 'to' => $to],
            'gate_pct_max' => self::GATE_PCT,
            'fixtures' => $fixtureCount,
            'months_checked' => $checked,
            'violations_count' => count($violations),
            'violations' => $violations,
            'max_deviation_pct_by_recipient' => $maxByRecipient,
            'money_tables_moved' => $moved,
            'fingerprints' => [
                'teacher_payouts_before' => $beforeP, 'teacher_payouts_after' => $afterP,
                'payments_before' => $beforePay, 'payments_after' => $afterPay,
                'users_before' => $beforeU, 'users_after' => $afterU,
            ],
            'passed' => $violations === [] && $checked > 0 && ! $moved,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info("окно {$from}..{$to}: получателей-фикстур {$fixtureCount}, месяцев проверено {$checked}");
            foreach ($maxByRecipient as $slug => $dev) {
                $this->line(sprintf('  %-14s max %.4f %%', $slug, $dev));
            }
            foreach ($violations as $v) {
                $this->warn(sprintf(
                    'VIOLATION %s %s [%s]: expected %s, computed %s (%.4f %%)',
                    $v['recipient'], $v['month'], $v['anchor'], $v['expected'], $v['computed'], $v['deviation_pct']
                ));
            }
            $this->line('money_tables_moved='.($moved ? 'yes' : 'no'));
            $this->info('BACKTEST GATE: '.(($summary['passed']) ? 'PASS' : 'FAIL'));
        }

        return $summary['passed'] ? self::SUCCESS : self::FAILURE;
    }

    /** @param  array<string, mixed>  $fx */
    private function violation(string $file, array $fx, string $month, string $anchor, mixed $expected, mixed $computed, float $dev): array
    {
        return [
            'file' => basename($file),
            'recipient' => (string) ($fx['display'] ?? $fx['recipient']),
            'slug' => (string) $fx['recipient'],
            'month' => $month,
            'anchor' => $anchor,
            'expected' => $expected,
            'computed' => $computed,
            'deviation_pct' => $dev,
        ];
    }
}
