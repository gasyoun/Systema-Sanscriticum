<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MoneyMovement;
use App\Services\Ledger\LedgerInvariantViolation;
use App\Services\Ledger\LedgerProjection;
use App\Services\Ledger\LedgerService;
use App\Services\Ledger\LegacyLedgerMapper;
use App\Support\Kopecks;
use Illuminate\Console\Command;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * H5443 (P1): report-only каркас бэкфилла денежного ядра.
 *
 * Ничего не переносит. По умолчанию строит план отображения легаси-платежей
 * на движения/обязательства/распределения в памяти и сверяет его до копейки.
 * С `--shadow` каждый план проводится через настоящие триггеры ядра внутри
 * транзакции, которая ВСЕГДА откатывается ({@see LedgerService::shadow()}):
 * отказ триггера — это аномалия легаси-данных, а не ошибка отчёта.
 *
 * Read-only доказывается счётчиком: любой пишущий SQL вне таблиц money_* —
 * FAIL; в режиме без `--shadow` FAIL — любой пишущий SQL вообще. Персональные
 * данные не печатаются: только id платежей.
 */
final class MoneyLedgerBackfillReport extends Command
{
    protected $signature = 'money:ledger-backfill-report
        {--shadow : провести планы через триггеры ядра внутри откатываемой транзакции}
        {--json= : записать полный отчёт в JSON-файл (локальный файл, не БД)}
        {--limit=20 : сколько id-примеров печатать на аномалию}';

    protected $description = 'H5443: report-only план бэкфилла денежного ядра, сверка до копейки (легаси не меняется)';

    private int $legacyWrites = 0;

    private int $ledgerWrites = 0;

    public function handle(LegacyLedgerMapper $mapper, LedgerService $ledger, LedgerProjection $projection): int
    {
        $shadow = (bool) $this->option('shadow');
        $limit = max(0, (int) $this->option('limit'));

        DB::listen(function (QueryExecuted $q): void {
            if (! preg_match('/^\s*(insert|update|delete|replace|alter|create|drop|truncate)\b/i', $q->sql)) {
                return;
            }
            preg_match('/^\s*(?:insert\s+into|update|delete\s+from|replace\s+into)\s+[`"]?(\w+)/i', $q->sql, $m);
            if (str_starts_with($m[1] ?? '', 'money_')) {
                $this->ledgerWrites++;
            } else {
                $this->legacyWrites++;
            }
        });

        $before = $this->ledgerCounts();
        $report = [
            'generated_at' => now()->toIso8601String(),
            'mode' => $shadow ? 'shadow' : 'plan',
            'ledger_flag_live' => (bool) config('features.money_ledger_core'),
        ];

        // Теневой прогон откатывается ПО СЕМЬЕ: ни одна блокировка (строки
        // money_*, FK-общие блокировки users/courses/teachers) не живёт дольше
        // одной семьи. Повтор доказательства между семьями ловит маппер в PHP.
        $report += $this->walk($mapper, $shadow ? $ledger : null, $projection);

        $after = $this->ledgerCounts();
        $report['ledger_rows_before'] = $before;
        $report['ledger_rows_after'] = $after;
        $report['db_writes_legacy'] = $this->legacyWrites;
        $report['db_writes_ledger_rolled_back'] = $this->ledgerWrites;

        $this->render($report, $limit);

        if ($path = $this->option('json')) {
            file_put_contents((string) $path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->line('JSON: '.$path);
        }

        $fail = [];
        if ($this->legacyWrites > 0) {
            $fail[] = "{$this->legacyWrites} пишущих SQL вне money_*";
        }
        if (! $shadow && $this->ledgerWrites > 0) {
            $fail[] = "{$this->ledgerWrites} пишущих SQL в money_* без --shadow";
        }
        if ($before !== $after) {
            $fail[] = 'число строк money_* изменилось после прогона';
        }
        if ($report['totals']['drift_kopecks'] !== 0) {
            $fail[] = 'план расходится с легаси на '.$report['totals']['drift_kopecks'].' коп.';
        }
        if ($fail !== []) {
            $this->error('FAIL: '.implode('; ', $fail));

            return self::FAILURE;
        }

        $this->info('db_writes_legacy: 0 · money_* без изменений (report-only подтверждён)');

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function walk(LegacyLedgerMapper $mapper, ?LedgerService $ledger, LedgerProjection $projection): array
    {
        $categories = [];
        $anomalies = [];
        $totals = [
            'families' => 0,
            'legacy_net_kopecks' => 0,
            'plan_net_kopecks' => 0,
            'plan_allocated_kopecks' => 0,
            'plan_residue_kopecks' => 0,
            'out_of_scope_kopecks' => 0,
            'drift_kopecks' => 0,
        ];
        $shadowStats = ['posted' => 0, 'rejected' => 0, 'ledger_net_kopecks' => 0, 'ledger_allocated_kopecks' => 0, 'integrity_breaches' => []];

        foreach ($mapper->families() as $plan) {
            $totals['families']++;
            $cat = $plan['category'];
            $categories[$cat] ??= ['families' => 0, 'kopecks' => 0];
            $categories[$cat]['families']++;
            $categories[$cat]['kopecks'] += $plan['legacy_kopecks'];
            foreach ($plan['anomalies'] as $a) {
                $anomalies[$a][] = $plan['payment_id'];
            }

            if ($plan['receipt'] === null) {
                // Расход без связи, выплата (P2), неположительная строка — вне плана ядра P1.
                $totals['out_of_scope_kopecks'] += $plan['legacy_kopecks'];

                continue;
            }

            $refunded = array_sum(array_column($plan['refunds'], 'kopecks'));
            $legacyNet = $plan['legacy_kopecks'] - $refunded;
            $allocated = LegacyLedgerMapper::allocatedKopecks($plan);
            $planNet = $plan['receipt']['kopecks'] - $refunded;

            $totals['legacy_net_kopecks'] += $legacyNet;
            $totals['plan_net_kopecks'] += $planNet;
            $totals['plan_allocated_kopecks'] += $allocated;
            $totals['plan_residue_kopecks'] += $plan['receipt']['kopecks'] - $allocated;
            $totals['drift_kopecks'] += $planNet - $legacyNet;

            if ($ledger !== null) {
                try {
                    [$net, $allocatedInLedger, $breaches] = $ledger->shadow(fn () => [
                        $this->post($ledger, $projection, $plan),
                        (int) DB::table('money_allocations')->sum('amount_kopecks'),
                        $projection->integrityBreaches(),
                    ]);
                    $shadowStats['posted']++;
                    $shadowStats['ledger_net_kopecks'] += $net;
                    $shadowStats['ledger_allocated_kopecks'] += $allocatedInLedger;
                    foreach (array_keys($breaches) as $breach) {
                        $shadowStats['integrity_breaches'][$breach][] = $plan['payment_id'];
                    }
                    if ($net !== $legacyNet) {
                        $anomalies['shadow_net_mismatch'][] = $plan['payment_id'];
                    }
                } catch (LedgerInvariantViolation $e) {
                    $shadowStats['rejected']++;
                    $reason = 'shadow_rejected: '.preg_replace('/\s*\(.*$/', '', $e->getMessage());
                    $anomalies[$reason][] = $plan['payment_id'];
                } catch (QueryException $e) {
                    // Не правило ядра (например, FK на удалённого студента/курс) — тоже аномалия данных.
                    $shadowStats['rejected']++;
                    $anomalies['shadow_rejected: db constraint '.($e->errorInfo[0] ?? '?')][] = $plan['payment_id'];
                }
            }
        }

        ksort($anomalies);

        $out = [
            'totals' => $totals,
            'categories' => $categories,
            'anomalies' => array_map(fn (array $ids) => ['count' => count($ids), 'payment_ids' => $ids], $anomalies),
        ];
        if ($ledger !== null) {
            ksort($shadowStats['integrity_breaches']);
            $out['shadow'] = $shadowStats;
        }

        return $out;
    }

    /**
     * Проводит один план через сервис (внутри теневой транзакции семьи).
     *
     * @param  array<string, mixed>  $plan
     * @return int чистая сумма движений семьи в ядре
     */
    private function post(LedgerService $ledger, LedgerProjection $projection, array $plan): int
    {
        $pid = $plan['payment_id'];
        $r = $plan['receipt'];
        $opts = ['legacy_payment_id' => $pid, 'reason' => 'H5443 shadow backfill'];

        if ($r['type'] === MoneyMovement::DIRECT_TEACHER_RECEIPT) {
            if ($r['teacher_id'] === null || $r['evidence_key'] === null) {
                throw new LedgerInvariantViolation('ledger: direct teacher receipt needs teacher and evidence');
            }
            [$receipt] = $ledger->directTeacherReceipt("legacy:{$pid}", $r['kopecks'], (int) $plan['user_id'], $plan['course_id'], (int) $r['teacher_id'], $r['source_currency'], $r['kopecks'], $r['evidence_key'], $plan['occurred_at'], $opts);
        } else {
            $receipt = $ledger->receipt("legacy:{$pid}", $r['kopecks'], (int) $plan['user_id'], $plan['course_id'], $plan['occurred_at'], $opts + array_filter(['evidence_key' => $r['evidence_key']]));
        }

        $obligations = [];
        foreach ($plan['obligations'] as $o) {
            $obligations[] = match ($o['kind']) {
                'block' => $ledger->openBlock($o['key'], (int) $plan['user_id'], (int) $plan['course_id'], (int) $o['block'], $o['list'], $o['discount'], ['legacy_payment_id' => $pid]),
                'trial' => $ledger->openTrial($o['key'], (int) $plan['user_id'], $plan['course_id'], $o['list'], $o['discount'], ['legacy_payment_id' => $pid]),
                'deposit' => $ledger->openDeposit($o['key'], (int) $plan['user_id'], $plan['course_id'], $o['list'], ['legacy_payment_id' => $pid]),
            };
        }
        foreach ($plan['allocations'] as $i => [$idx, $kopecks]) {
            if ($kopecks > 0) {
                $ledger->allocate($receipt, $obligations[$idx], $kopecks, "legacy:{$pid}:alloc:{$i}");
            }
        }

        // Легаси не говорит, из какого блока возврат: снимаем с последних
        // обязательств (LIFO), остаток возврата — из нераспределённой части.
        foreach ($plan['refunds'] as $f) {
            $from = [];
            $left = $f['kopecks'] - max(0, $projection->unallocatedResidue($receipt->id));
            foreach (array_reverse($obligations) as $o) {
                if ($left <= 0) {
                    break;
                }
                if ($o->fresh()->delivered_at !== null) {
                    continue; // D6: признанное занятие возвратом не уменьшается
                }
                $held = $projection->obligationAllocated($o->id);
                $take = min($held, $left);
                if ($take > 0) {
                    $from[$o->id] = $take;
                    $left -= $take;
                }
            }
            $ledger->refund($receipt, "legacy:{$f['payment_id']}", $f['kopecks'], $f['occurred_at'], $from, ['legacy_payment_id' => $f['payment_id']]);
        }

        return (int) DB::table('money_movements')
            ->where(fn ($q) => $q->where('id', $receipt->id)->orWhere('cap_anchor_id', $receipt->id))
            ->sum('amount_kopecks');
    }

    /** @return array<string, int> */
    private function ledgerCounts(): array
    {
        return [
            'money_movements' => DB::table('money_movements')->count(),
            'money_allocations' => DB::table('money_allocations')->count(),
            'money_obligations' => DB::table('money_obligations')->count(),
        ];
    }

    /** @param array<string, mixed> $r */
    private function render(array $r, int $limit): void
    {
        $t = $r['totals'];
        $this->info("H5443 ledger backfill report · {$r['generated_at']} · mode={$r['mode']} · ledger_flag_live=".json_encode($r['ledger_flag_live']));
        $this->line("Семей: {$t['families']} · легаси нетто ".$this->rub($t['legacy_net_kopecks']).' · план нетто '.$this->rub($t['plan_net_kopecks'])
            .' · расхождение '.$t['drift_kopecks'].' коп.');
        $this->line('Распределено '.$this->rub($t['plan_allocated_kopecks']).' · явный остаток '.$this->rub($t['plan_residue_kopecks'])
            .' · вне плана P1 '.$this->rub($t['out_of_scope_kopecks']));
        $this->line('Категории:');
        foreach ($r['categories'] as $cat => $c) {
            $this->line("   {$cat}: {$c['families']} семей, ".$this->rub($c['kopecks']));
        }
        $this->line('Аномалии (только id платежей):');
        foreach ($r['anomalies'] as $name => $a) {
            $this->line("   {$name}: {$a['count']} · ".json_encode(array_slice($a['payment_ids'], 0, $limit)));
        }
        if (isset($r['shadow'])) {
            $s = $r['shadow'];
            $this->line("Теневой прогон: проведено {$s['posted']}, отклонено триггерами {$s['rejected']}, нетто ядра ".$this->rub($s['ledger_net_kopecks'])
                .' · распределено ядром '.$this->rub($s['ledger_allocated_kopecks'])
            .' · нарушения целостности (id платежей): '.json_encode($s['integrity_breaches']));
        }
    }

    private function rub(int $kopecks): string
    {
        return Kopecks::toDecimal($kopecks).' ₽';
    }
}
