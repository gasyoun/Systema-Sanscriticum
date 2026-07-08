<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\SendMessengerAlerts;
use App\Models\Group;

/**
 * Рассылка уже оплатившим/присоединённым студентам группы о том, что старт
 * под вопросом — набор ещё не закрыт (H162). Симметрична SendMessengerAlerts-
 * рассылке classes:remind-upcoming, но адресована активному составу группы
 * (Group::activeUsers()), а не расписанию занятия.
 */
class GroupRecruitmentNotifier
{
    /**
     * Разослать «группа пока набирается» активному составу и отметить группу
     * как уведомленную (recruitment_notified_at). Используется и плановым
     * прогоном (за N дней до старта), и немедленно при переносе даты.
     */
    public function notifyShortfall(Group $group): int
    {
        $text = $this->buildText($group);

        $sent = 0;
        $group->activeUsers()->chunkById(200, function ($users) use ($text, &$sent): void {
            foreach ($users as $user) {
                SendMessengerAlerts::dispatch($user, $text);
                $sent++;
            }
        });

        $group->forceFill(['recruitment_notified_at' => now()])->save();

        return $sent;
    }

    private function buildText(Group $group): string
    {
        $course = $group->courses()->first()?->title ?? $group->name;
        $date = $group->effectiveStartDate()?->format('d.m.Y') ?? '—';

        return "📋 <b>Набор пока не закрыт</b>\n\n"
            ."Группа «{$course}» пока набирается, старт {$date} под вопросом — "
            .'как только наберём минимум участников, сообщим точную дату.';
    }
}
