<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Payment;
use App\Models\RevenueSchedule;
use App\Services\RevenueRecognitionService;
use App\Services\RevenueScheduleService;
use Illuminate\Console\Command;

/**
 * Бэкофилл графика признания выручки (H258, фаза B) по историческим платежам.
 * Обсёрвер строит строки только на НОВЫЕ/изменённые платежи — эта команда
 * покрывает всё, что было оплачено ДО внедрения accrual. Идемпотентна:
 * пересобирает субледжер с нуля (regenerateFor = delete+insert на платёж),
 * поэтому её можно гонять повторно для лечения дрейфа.
 *
 * --dry-run — только показать, сколько строк дал бы пересбор, БЕЗ записи в БД.
 */
class BackfillRevenueSchedule extends Command
{
    protected $signature = 'revenue:backfill-schedule {--dry-run : Показать план пересбора без записи в БД}';

    protected $description = 'Пересобрать график признания выручки (revenue_schedules) по всем платежам (accrual-субледжер).';

    public function handle(RevenueScheduleService $service, RevenueRecognitionService $recognition): int
    {
        if ($this->option('dry-run')) {
            return $this->dryRun($recognition);
        }

        $before = RevenueSchedule::query()->count();
        $this->info("Пересобираю график признания выручки… (было строк: {$before})");

        $stats = $service->backfillAll();

        $this->info("Готово. Обработано платежей: {$stats['payments']}; строк признания: {$stats['rows']}.");

        return self::SUCCESS;
    }

    /**
     * Сухой прогон: считаем, сколько платежей образуют выручку и сколько строк
     * признания получилось бы, ничего не записывая.
     */
    private function dryRun(RevenueRecognitionService $recognition): int
    {
        $payments = 0;
        $revenuePayments = 0;
        $rows = 0;

        Payment::query()->orderBy('id')->chunkById(500, function ($chunk) use ($recognition, &$payments, &$revenuePayments, &$rows) {
            foreach ($chunk as $payment) {
                $payments++;
                $shares = $recognition->sharesForPayment($payment);
                if (! empty($shares)) {
                    $revenuePayments++;
                    $rows += count($shares);
                }
            }
        });

        $this->info('Сухой прогон (запись НЕ выполнялась):');
        $this->line("  всего платежей:        {$payments}");
        $this->line("  образуют выручку:      {$revenuePayments}");
        $this->line("  строк признания будет: {$rows}");

        return self::SUCCESS;
    }
}
