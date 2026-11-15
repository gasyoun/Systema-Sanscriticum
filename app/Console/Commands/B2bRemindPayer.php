<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Payments\B2bPayerReminderService;
use Illuminate\Console\Command;

/**
 * H8250 — B2B-напоминания плательщику: после даты последнего урока покрытого
 * b2b-блока — ровно одно Telegram-напоминание оплатить следующий блок
 * (без Telegram у плательщика — задача кураторам). Вся логика «кому и когда» —
 * в B2bPayerReminderService; здесь гейт, прогон и отчёт.
 */
class B2bRemindPayer extends Command
{
    protected $signature = 'b2b:remind-payer {--dry-run : Показать назревшие напоминания без отправки и записи}';

    protected $description = 'Напоминает b2b-плательщику оплатить следующий блок после завершения текущего.';

    public function handle(B2bPayerReminderService $service): int
    {
        if (! B2bPayerReminderService::enabled()) {
            $this->info('B2B-напоминания плательщику выключены (features.b2b_payer_reminders / features.b2b_group_payment) — пропуск.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $due = 0;
            foreach ($service->payments() as $payment) {
                $nextBlock = $service->dueNextBlock($payment);
                if ($nextBlock !== null) {
                    $due++;
                    $payerName = $payment->user?->name ?? '—';
                    $this->line(sprintf(
                        '[dry-run] платёж #%d (курс #%d, группа #%d, плательщик %s) → блок %d',
                        $payment->id,
                        $payment->course_id,
                        $payment->group_id,
                        $payerName,
                        $nextBlock,
                    ));
                }
            }

            $this->info("Назревших напоминаний: {$due}.");

            return self::SUCCESS;
        }

        $stats = $service->run();

        $this->info(sprintf(
            'B2B-напоминания: проверено %d, отправлено %d, задач кураторам %d, пропущено %d.',
            $stats['checked'],
            $stats['sent'],
            $stats['curator_tasks'],
            $stats['skipped'],
        ));

        return self::SUCCESS;
    }
}
