<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Course;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\Teacher;
use App\Models\TeacherPayout;
use App\Services\Payments\PaypalClaimAmountCheck;
use App\Services\PayoutRunService;
use App\Services\TeacherSalaryService;
use Illuminate\Console\Command;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * H5442 (P0, D5): read-only отчёт о строках, которые затронет волна
 * исправлений `features.payment_fix_wave1`, — ДО её включения и после.
 *
 * Ничего не пишет: весь прогон идёт внутри транзакции, которая всегда
 * откатывается, а каждый пишущий SQL (insert/update/delete/replace/alter/
 * create/drop/truncate) считается и печатается — ненулевой счётчик =
 * FAIL (exit 1). Персональные данные не печатаются: только id.
 *
 * Разделы:
 *  1. paypal_claims      — заявки PayPal против ожидаемой цены (D4/D20);
 *  2. duplicate_claims   — повторы по стабильному ключу / txn (D4);
 *  3. refund_rededuction — возвратные строки в нескольких выплатах (D11);
 *  4. duplicate_packages — две+ поблочные выплаты на один пакет (D13);
 *  5. payout_runs        — прогон выплат флаг OFF vs ON: итог/EUR/исключения (D14);
 *  6. access_fail_closed — курсы без групп доступа и paid-строки на них (п.4).
 */
final class MoneyP0WaveReport extends Command
{
    protected $signature = 'money:p0-wave-report
        {--json= : записать полный отчёт в JSON-файл (локальный файл, не БД)}
        {--skip-runs : не считать прогоны выплат (раздел 5 — самый медленный)}
        {--limit=50 : сколько строк-примеров печатать на раздел}';

    protected $description = 'H5442: read-only отчёт о строках, затрагиваемых волной payment_fix_wave1 (пишет 0 строк)';

    private int $writes = 0;

    public function handle(PaypalClaimAmountCheck $amounts, PayoutRunService $runs, TeacherSalaryService $salaries): int
    {
        $startedAt = now()->toIso8601String();

        DB::listen(function (QueryExecuted $q): void {
            if (preg_match('/^\s*(insert|update|delete|replace|alter|create|drop|truncate)\b/i', $q->sql)) {
                $this->writes++;
            }
        });

        $report = [];
        DB::beginTransaction();
        try {
            $report = [
                'generated_at' => $startedAt,
                'wave_flag_live' => (bool) config('features.payment_fix_wave1'),
                'grant_access_fail_closed_live' => (bool) config('features.grant_access_fail_closed'),
                'paypal_claims' => $this->paypalClaims($amounts),
                'duplicate_claims' => $this->duplicateClaims(),
                'refund_rededuction' => $this->refundRededuction(),
                'duplicate_packages' => $this->duplicatePackages(),
                'payout_runs' => $this->option('skip-runs') ? ['skipped' => true] : $this->payoutRuns($runs),
                'access_fail_closed' => $this->accessFailClosed(),
            ];
        } finally {
            DB::rollBack();
        }

        $report['db_writes'] = $this->writes;

        $this->render($report);

        if ($path = $this->option('json')) {
            file_put_contents((string) $path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->line('JSON: '.$path);
        }

        if ($this->writes > 0) {
            $this->error("FAIL: отчёт выполнил {$this->writes} пишущих SQL (всё откачено) — read-only нарушен.");

            return self::FAILURE;
        }

        $this->info('db_writes: 0 (read-only подтверждён)');

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function paypalClaims(PaypalClaimAmountCheck $amounts): array
    {
        $byVerdict = [];
        $rows = [];
        $tariffs = [];

        Payment::query()
            ->where('provider', Payment::PROVIDER_PAYPAL)
            ->whereNotNull('foreign_amount')
            ->whereNotNull('foreign_currency')
            ->orderBy('id')
            ->each(function (Payment $p) use ($amounts, &$byVerdict, &$rows, &$tariffs): void {
                if ($p->claimMeta('supplement')) {
                    return;
                }
                $tariff = $tariffs[$p->course_id.'|'.$p->tariff] ??= $this->tariffFor($p);
                $check = $tariff !== null
                    ? $amounts->check($tariff, (string) $p->foreign_currency, (float) $p->foreign_amount, $p->user)
                    : PaypalClaimAmountCheck::classify(strtoupper((string) $p->foreign_currency), (float) $p->foreign_amount, null);

                $byVerdict[$check['verdict']] = ($byVerdict[$check['verdict']] ?? 0) + 1;

                // Под волной авто-доверенная заявка с таким вердиктом осталась бы pending.
                $trusted = $p->isAutoTrustedPaypal();
                $wouldStayPending = $trusted && ! $check['auto_confirm'];
                if ($check['verdict'] !== PaypalClaimAmountCheck::EXACT) {
                    $rows[] = [
                        'payment_id' => (int) $p->id,
                        'status' => (string) $p->status,
                        'auto_trusted' => $trusted,
                        'verdict' => $check['verdict'],
                        'currency' => $check['currency'],
                        'claimed' => $check['claimed'],
                        'expected_now' => $check['expected'],
                        'deviation_pct' => $check['deviation_pct'],
                        'would_stay_pending_under_wave' => $wouldStayPending,
                        'tariff_found' => $tariff !== null,
                    ];
                }
            });

        return [
            'note' => 'ожидаемая цена — ТЕКУЩАЯ (published list / config на момент отчёта), не цена на дату заявки',
            'by_verdict' => $byVerdict,
            'trusted_that_would_stay_pending' => count(array_filter($rows, fn ($r) => $r['would_stay_pending_under_wave'])),
            'rows' => $rows,
        ];
    }

    private function tariffFor(Payment $p): ?Tariff
    {
        if (! $p->course_id || ! $p->tariff) {
            return null;
        }

        return Tariff::query()
            ->where('course_id', $p->course_id)
            ->get()
            ->first(fn (Tariff $t) => $t->accessKey() === $p->tariff);
    }

    /** @return array<string, mixed> */
    private function duplicateClaims(): array
    {
        $groups = [];
        Payment::query()
            ->where('provider', Payment::PROVIDER_PAYPAL)
            ->whereIn('status', ['pending', 'paid'])
            ->orderBy('id')
            ->each(function (Payment $p) use (&$groups): void {
                if ($p->claimMeta('supplement')) {
                    return;
                }
                $key = PaypalClaimAmountCheck::replayKey(
                    (int) $p->user_id,
                    0, // тариф-id исторически не хранится; группируем по ключу доступа ниже
                    $p->claimMeta('txn'),
                    (string) $p->claimMeta('paid_on', ''),
                    (string) $p->foreign_currency,
                    (float) $p->foreign_amount,
                );
                $groupKey = $p->claimMeta('txn') ? $key : $key.'|'.$p->course_id.'|'.$p->tariff;
                $groups[$groupKey][] = ['payment_id' => (int) $p->id, 'status' => (string) $p->status, 'has_txn' => (bool) $p->claimMeta('txn')];
            });

        $dups = array_values(array_filter($groups, fn (array $g) => count($g) > 1));

        return ['groups' => count($dups), 'extra_rows' => array_sum(array_map(fn ($g) => count($g) - 1, $dups)), 'rows' => $dups];
    }

    /** @return array<string, mixed> */
    private function refundRededuction(): array
    {
        $seen = []; // teacher|course|block|payment => [payout ids]
        TeacherPayout::query()->whereNotNull('breakdown')->orderBy('id')->each(function (TeacherPayout $payout) use (&$seen): void {
            $b = $payout->breakdown ?? [];
            if (! isset($b['course_id'], $b['block_number'])) {
                return;
            }
            foreach ($b['payments'] ?? [] as $line) {
                if (! empty($line['is_return']) && isset($line['payment_id'])) {
                    $seen[$payout->teacher_id.'|'.$b['course_id'].'|'.$b['block_number'].'|'.$line['payment_id']][] = (int) $payout->id;
                }
            }
        });

        $repeated = [];
        foreach ($seen as $key => $payoutIds) {
            if (count($payoutIds) > 1) {
                [$teacherId, $courseId, $block, $paymentId] = explode('|', $key);
                $repeated[] = ['teacher_id' => (int) $teacherId, 'course_id' => (int) $courseId, 'block_number' => (int) $block, 'return_payment_id' => (int) $paymentId, 'payout_ids' => $payoutIds];
            }
        }

        return [
            'return_lines_recorded' => count($seen),
            'withheld_more_than_once' => count($repeated),
            'rows' => $repeated,
        ];
    }

    /** @return array<string, mixed> */
    private function duplicatePackages(): array
    {
        $groups = [];
        TeacherPayout::query()->whereNotNull('breakdown')->orderBy('id')->each(function (TeacherPayout $payout) use (&$groups): void {
            $b = $payout->breakdown ?? [];
            if (! isset($b['course_id'], $b['block_number']) || $payout->type === TeacherPayout::TYPE_ADVANCE) {
                return;
            }
            $key = TeacherPayout::blockSettlementKey((int) $payout->teacher_id, (int) $b['course_id'], (int) $b['block_number'], isset($b['group_id']) ? (int) $b['group_id'] : null);
            $groups[$key][] = ['payout_id' => (int) $payout->id, 'teacher_id' => (int) $payout->teacher_id, 'course_id' => (int) $b['course_id'], 'block_number' => (int) $b['block_number'], 'amount' => (float) $payout->amount, 'paid_at' => $payout->paid_at?->toDateString()];
        });

        $dups = array_values(array_filter($groups, fn (array $g) => count($g) > 1));

        return [
            'block_payouts' => count($groups),
            'packages_with_duplicates' => count($dups),
            'negative_payouts' => TeacherPayout::query()->where('amount', '<', 0)->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'rows' => $dups,
            'note' => 'история не переписывается: под волной новый повтор отклоняется, старые дубли — исключения сверки',
        ];
    }

    /** @return array<string, mixed> */
    private function payoutRuns(PayoutRunService $runs): array
    {
        $original = (bool) config('features.payment_fix_wave1');
        $changed = [];
        $exceptions = [];
        $teachers = 0;

        foreach (Teacher::query()->orderBy('id')->get() as $teacher) {
            config(['features.payment_fix_wave1' => false]);
            $off = $runs->runForTeacher($teacher);
            config(['features.payment_fix_wave1' => true]);
            $on = $runs->runForTeacher($teacher);
            config(['features.payment_fix_wave1' => $original]);

            if (isset($off['error']) || isset($on['error'])) {
                continue;
            }
            $teachers++;

            $delta = [
                'payable_rub' => [(float) $off['payable_rub'], (float) $on['payable_rub']],
                'payable_eur' => [$off['payable_eur'], $on['payable_eur']],
                'prior_rub' => [(float) $off['prior_rub'], (float) $on['prior_rub']],
            ];
            if ($delta['payable_rub'][0] !== $delta['payable_rub'][1]
                || $delta['payable_eur'][0] !== $delta['payable_eur'][1]
                || $delta['prior_rub'][0] !== $delta['prior_rub'][1]) {
                $changed[] = ['teacher_id' => (int) $teacher->id, 'window' => $on['window']] + $delta;
            }
            foreach ($on['reconciliation_exceptions'] ?? [] as $e) {
                $exceptions[] = ['teacher_id' => (int) $teacher->id] + $e;
            }
        }
        config(['features.payment_fix_wave1' => $original]);

        return ['teachers_with_runs' => $teachers, 'changed' => $changed, 'exceptions_under_wave' => $exceptions];
    }

    /** @return array<string, mixed> */
    private function accessFailClosed(): array
    {
        $coursesWithoutGroups = Course::query()->whereDoesntHave('groups')->pluck('id')->map(fn ($id) => (int) $id)->all();

        $sellable = Tariff::query()
            ->where('is_active', true)
            ->whereIn('course_id', $coursesWithoutGroups)
            ->pluck('course_id')->unique()->values()->map(fn ($id) => (int) $id)->all();

        $paidOnGroupless = Payment::query()
            ->where('status', 'paid')
            ->whereIn('course_id', $coursesWithoutGroups)
            ->whereNotIn('tariff', array_merge(TeacherSalaryService::NON_REVENUE_TARIFFS, ['deposit', 'trial', 'marathon_paid', 'donation', 'gift']))
            ->where('amount', '>', 0)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        return [
            'courses_without_groups' => count($coursesWithoutGroups),
            'sellable_courses_without_groups' => $sellable,
            'paid_rows_on_groupless_courses' => count($paidOnGroupless),
            'paid_row_ids' => $paidOnGroupless,
            'note' => 'под волной новая оплата такого курса не проходит молча: grantAccess бросает, PayPal-заявка ложится pending с reconciliation_exception=no_access_groups',
        ];
    }

    /** @param array<string, mixed> $r */
    private function render(array $r): void
    {
        $limit = (int) $this->option('limit');
        $this->info('H5442 money P0 wave report · '.$r['generated_at'].' · wave_flag_live='.json_encode($r['wave_flag_live']));

        $pc = $r['paypal_claims'];
        $this->line('1. PayPal-заявки по вердикту: '.json_encode($pc['by_verdict'], JSON_UNESCAPED_UNICODE)
            .' · авто-доверенных, что под волной остались бы pending: '.$pc['trusted_that_would_stay_pending']);
        foreach (array_slice($pc['rows'], 0, $limit) as $row) {
            $this->line('   '.json_encode($row, JSON_UNESCAPED_UNICODE));
        }

        $dc = $r['duplicate_claims'];
        $this->line("2. Повторы заявок: групп {$dc['groups']}, лишних строк {$dc['extra_rows']}");
        foreach (array_slice($dc['rows'], 0, $limit) as $row) {
            $this->line('   '.json_encode($row));
        }

        $rr = $r['refund_rededuction'];
        $this->line("3. Возвраты: записано строк {$rr['return_lines_recorded']}, удержано больше одного раза {$rr['withheld_more_than_once']}");
        foreach (array_slice($rr['rows'], 0, $limit) as $row) {
            $this->line('   '.json_encode($row));
        }

        $dp = $r['duplicate_packages'];
        $this->line("4. Поблочные выплаты: пакетов {$dp['block_payouts']}, с дублями {$dp['packages_with_duplicates']}, отрицательных выплат ".count($dp['negative_payouts']));
        foreach (array_slice($dp['rows'], 0, $limit) as $row) {
            $this->line('   '.json_encode($row));
        }

        $pr = $r['payout_runs'];
        if (! empty($pr['skipped'])) {
            $this->line('5. Прогоны выплат: пропущено (--skip-runs)');
        } else {
            $this->line('5. Прогоны выплат: преподавателей '.$pr['teachers_with_runs'].', итог меняется у '.count($pr['changed']).', исключений под волной '.count($pr['exceptions_under_wave']));
            foreach (array_slice($pr['changed'], 0, $limit) as $row) {
                $this->line('   '.json_encode($row));
            }
            foreach (array_slice($pr['exceptions_under_wave'], 0, $limit) as $row) {
                $this->line('   ! '.json_encode($row, JSON_UNESCAPED_UNICODE));
            }
        }

        $af = $r['access_fail_closed'];
        $this->line("6. Доступ fail-closed: курсов без групп {$af['courses_without_groups']}, из них продаются ".count($af['sellable_courses_without_groups'])
            .' '.json_encode($af['sellable_courses_without_groups']).", paid-строк на таких курсах {$af['paid_rows_on_groupless_courses']}");
    }
}
