<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SendMessengerAlerts;
use App\Models\Group;
use App\Models\MarketingSetting;
use App\Models\User;
use App\Services\CuratorNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class NotifyFormingGroupShortfall extends Command
{
    protected $signature = 'groups:notify-forming-shortfall';

    protected $description = 'За N дней до плановой/перенесённой даты старта шлёт студентам и кураторам честный статус недобора по группам в наборе (H162).';

    public function handle(CuratorNotifier $curatorNotifier): int
    {
        $settings = MarketingSetting::cached();
        if ($settings && ! $settings->recruitment_notify_enabled) {
            $this->info('Уведомления о недоборе отключены в настройках — пропуск.');

            return self::SUCCESS;
        }

        $leadDays = (int) ($settings?->recruitment_notify_lead_days ?? 2);
        $today = Carbon::today();

        // Кандидаты на уведомление — фильтр по эффективной дате и размеру
        // делаем в PHP, т.к. effectiveStartDate() комбинирует два nullable-поля.
        $groups = Group::query()
            ->where('status', Group::STATUS_FORMING)
            ->where(function ($q): void {
                $q->whereNotNull('planned_start_date')->orWhereNotNull('start_date_override');
            })
            ->whereNull('recruitment_notified_at')
            ->get()
            ->filter(function (Group $group) use ($today, $leadDays): bool {
                $start = $group->effectiveStartDate();

                return $start !== null
                    && $start->isSameDay($today->copy()->addDays($leadDays))
                    && $group->isUnderEnrolled();
            });

        $eventGroups = 0;
        $recipients = 0;

        foreach ($groups as $group) {
            $sent = 0;
            /** @var User $user */
            foreach ($group->activeUsers()->get() as $user) {
                if (! $user->telegram_id && ! $user->vk_id) {
                    continue;
                }
                SendMessengerAlerts::dispatch($user, $this->buildText($group));
                $sent++;
            }

            $curatorNotifier->groupUnderEnrolled($group);

            $group->update(['recruitment_notified_at' => now()]);

            $eventGroups++;
            $recipients += $sent;
        }

        $this->info("Уведомления о недоборе: групп {$eventGroups}, получателей {$recipients}.");

        return self::SUCCESS;
    }

    private function buildText(Group $group): string
    {
        $courseTitle = $group->courses()->first()?->title ?? $group->name;
        $date = $group->effectiveStartDate()?->format('d.m.Y') ?? '—';

        return "📋 <b>Группа пока набирается</b>\n\n".
            "Курс «{$courseTitle}» пока набирается, старт {$date} под вопросом — ".
            'как только наберём минимум, сообщим точную дату.';
    }
}
