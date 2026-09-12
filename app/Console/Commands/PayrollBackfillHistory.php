<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TeacherPayout;
use App\Services\Payroll\BackfillHistoryService;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * H4597 — backfill исторических выплат преподавателям из манифеста.
 *
 * Dry-run по умолчанию; --apply пишет ТОЛЬКО teacher_payouts и разрешён
 * исключительно после независимого PASS верификатора (class money, H4358,
 * раздел ## Verifier хендоффа H4597). Идемпотентно: повторный прогон
 * пропускает уже заведённые строки. Манифест живёт в приватном Uprava
 * (data/h4597_backfill_manifest_*.json), в публичный Systema не коммитится.
 */
class PayrollBackfillHistory extends Command
{
    protected $signature = 'payroll:backfill-history
        {--manifest= : Путь к JSON-манифесту (Uprava data/h4597_backfill_manifest_*.json)}
        {--teacher= : Только этот teacher_id}
        {--apply : Реально записать (без флага — только показать)}';

    protected $description = 'H4597: заводит исторические выплаты (teacher_payouts) по проверенному манифесту; dry-run по умолчанию';

    public function handle(BackfillHistoryService $service): int
    {
        $manifestPath = (string) $this->option('manifest');
        if ($manifestPath === '' || ! is_file($manifestPath)) {
            $this->error('--manifest= обязателен (путь к JSON-манифесту).');

            return self::FAILURE;
        }
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        if (! is_array($manifest)) {
            $this->error("манифест не читается: {$manifestPath}");

            return self::FAILURE;
        }

        $teacherFilter = $this->option('teacher') !== null ? (int) $this->option('teacher') : null;

        try {
            $plan = $service->plan($manifest, $teacherFilter);
        } catch (RuntimeException $e) {
            $this->error('ОТКАЗ (fail-closed): '.$e->getMessage());

            return self::FAILURE;
        }

        if ($plan['rows'] === []) {
            $this->info('Нечего заводить: все строки манифеста уже в базе или не содержат ключей.');

            foreach ($plan['skipped'] as $s) {
                $this->line("  · {$s['inventory_id']}: {$s['reason']}");
            }

            return self::SUCCESS;
        }

        $this->table(
            ['Строка', 'Преподаватель', 'Дата', 'Период', 'Сумма', 'Валюта', 'Долей (paidShareKeys)'],
            collect($plan['rows'])->map(fn (array $r): array => [
                $r['inventory_id'],
                $r['teacher_name'].' #'.$r['teacher_id'],
                $r['paid_at'],
                $r['period_month'],
                number_format($r['amount'], 2, '.', ' ').' ₽',
                $r['payout_currency'] !== null
                    ? $r['payout_currency'].' '.number_format((float) $r['amount_foreign'], 2, '.', ' ')
                    : '—',
                (string) $r['shares_n'],
            ])->all(),
        );

        foreach ($plan['flagged'] as $warning) {
            $this->warn('⚠ '.$warning);
        }
        foreach ($plan['skipped'] as $s) {
            $this->line("  · пропущено: {$s['inventory_id']} — {$s['reason']}");
        }

        $this->info(sprintf(
            'Итог: строк к записи %d, долей %d, на сумму %.2f ₽ (только teacher_payouts, без зеркала в Финансы).',
            $plan['totals']['inserts'], $plan['totals']['shares'], $plan['totals']['rub'],
        ));

        if (! $this->option('apply')) {
            $this->warn('Это предпросмотр (dry-run). Прод-запись (--apply) — только после PASS независимого верификатора (class money H4358).');

            return self::SUCCESS;
        }

        $created = $service->apply($plan);
        $this->info("Готово. Заведено строк: {$created}.");
        $this->line('Дальше (отдельно, после ревью): salary:post-payouts --apply для зеркала в «Финансах».');

        return self::SUCCESS;
    }
}
