<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\FollowUpTask;
use App\Services\WorkQueueReport;
use Illuminate\Console\Command;

/**
 * NOBORING dozhim Wave 1b (H2059, IMPLEMENTATION H-B п.4): заводит менеджеру
 * FollowUpTask на каждую недожатую open Deal, у которой ещё нет открытой
 * задачи (идемпотентный маркер = сама открытая задача — повторный прогон не
 * плодит дубли).
 *
 * Гейт — dozhim_queue (та же очередь, что и WorkQueueReport::unpaidOpenDeals()):
 * пока флаг OFF, бакет пуст и команда не создаёт ни одной задачи. Видимость
 * созданных задач в кокпите — отдельный флаг crm_follow_up_tasks (H1836),
 * этой командой не меняется: задачи создаются всегда, если dozhim_queue ON,
 * даже если крыло кокпита их ещё не показывает.
 */
class DozhimCreateFollowUpTasks extends Command
{
    protected $signature = 'dozhim:create-followups';

    protected $description = 'Заводит FollowUpTask менеджеру по каждой недожатой open Deal старше порога (идемпотентно)';

    public function handle(WorkQueueReport $report): int
    {
        if (! config('features.dozhim_queue')) {
            $this->info('Очередь дожима выключена (DOZHIM_QUEUE) — пропуск.');

            return self::SUCCESS;
        }

        $created = 0;
        $skipped = 0;

        foreach ($report->unpaidOpenDeals() as $deal) {
            $hasOpenTask = FollowUpTask::query()->where('deal_id', $deal->id)->open()->exists();

            if ($hasOpenTask) {
                $skipped++;

                continue;
            }

            FollowUpTask::create([
                'deal_id' => $deal->id,
                'assigned_to' => $deal->assigned_to,
                'type' => FollowUpTask::TYPE_CALL,
                'due_at' => now(),
            ]);

            $created++;
        }

        $this->info("Задачи дожима: создано {$created}, пропущено (уже есть открытая задача) {$skipped}.");

        return self::SUCCESS;
    }
}
