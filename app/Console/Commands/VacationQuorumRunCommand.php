<?php

namespace App\Console\Commands;

use App\Models\VacationQuorumPoll;
use App\Services\VacationQuorumService;
use Illuminate\Console\Command;

/**
 * H3790 фаза C: опрос кворума по каникульным группам.
 *
 * Ежедневный запуск. 25–31.08 спрашивает группы (is_on_vacation, без даты
 * выхода) в их TG-чатах через @zapisi_ORSbot; круглогодично доразрешает
 * истёкшие дедлайны (кворум есть/нет) и исполняет/убирает предложения о
 * роспуске.
 */
class VacationQuorumRunCommand extends Command
{
    protected $signature = 'schedule:vacation-quorum {--dry-run : без отправок — показать, что было бы сделано}';

    protected $description = 'Спросить каникульные группы «когда возобновляем?» и разрешить дедлайны кворума (H3790)';

    public function handle(VacationQuorumService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->info('== Разрешение дедлайнов ==');
        if ($dryRun) {
            $dueCount = VacationQuorumPoll::query()
                ->where('outcome', VacationQuorumPoll::OUTCOME_PENDING)
                ->where('deadline_at', '<', now())
                ->count();
            $this->line("дедлайн истёк у: {$dueCount}");
        } else {
            $service->resolveDue();
        }

        $this->info('== Вопрос группам ==');
        $groups = $service->groupsToAsk();
        if ($groups === []) {
            $this->line('нет групп для вопроса');

            return self::SUCCESS;
        }

        foreach ($groups as $group) {
            $this->line("спросить: {$group->name} (chat {$group->telegram_chat_id})");
            if (! $dryRun) {
                $service->ask($group);
            }
        }

        return self::SUCCESS;
    }
}
