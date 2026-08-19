<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Group;
use App\Models\MarketingSetting;
use App\Services\CuratorNotifier;
use App\Services\GroupRecruitmentNotifier;
use Illuminate\Console\Command;

/**
 * Ежедневный прогон (H162): группы со статусом «набирается», у которых
 * эффективная дата старта наступает через N дней (настройка
 * recruitment_notify_lead_days) и состав ещё не набран (min_size) —
 * предупреждаем уже присоединённых студентов и кураторов, вместо молчания
 * до дня старта.
 */
class NotifyFormingShortfall extends Command
{
    protected $signature = 'groups:notify-forming-shortfall';

    protected $description = 'Шлёт студентам и кураторам уведомление о недоборе группы за N дней до плановой даты старта.';

    public function handle(GroupRecruitmentNotifier $notifier, CuratorNotifier $curatorNotifier): int
    {
        $settings = MarketingSetting::cached();
        if ($settings && ! $settings->recruitment_notify_enabled) {
            $this->info('Уведомления о недоборе отключены в настройках — пропуск.');

            return self::SUCCESS;
        }

        $leadDays = (int) ($settings?->recruitment_notify_lead_days ?? 2);

        $groups = Group::query()
            ->where('status', 'forming')
            ->whereNotNull('planned_start_date')
            ->whereNull('recruitment_notified_at')
            ->get()
            ->filter(fn (Group $g): bool => $g->effectiveStartDate()?->isSameDay(today()->addDays($leadDays)) ?? false)
            ->filter(fn (Group $g): bool => $g->min_size !== null && $g->membersTowardMinSize() < $g->min_size);

        $recipients = 0;
        foreach ($groups as $group) {
            $recipients += $notifier->notifyShortfall($group);
            $curatorNotifier->groupUnderEnrolled($group);
        }

        $this->info("Уведомления о недоборе: групп {$groups->count()}, получателей {$recipients}.");

        return self::SUCCESS;
    }
}
