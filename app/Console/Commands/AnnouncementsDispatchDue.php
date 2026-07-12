<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Announcement;
use App\Services\AnnouncementDispatcher;
use Illuminate\Console\Command;

/**
 * Отправляет запланированные анонсы (Announcement.scheduled_at), у которых
 * наступило время (H816/S6) — снимает риск ручной «ночной» рассылки: анонс
 * готовится заранее, отправка происходит по расписанию без куратора.
 */
class AnnouncementsDispatchDue extends Command
{
    protected $signature = 'announcements:dispatch-due';

    protected $description = 'Разослать запланированные анонсы (Announcement.scheduled_at <= now, ещё не отправленные)';

    public function handle(AnnouncementDispatcher $dispatcher): int
    {
        $due = Announcement::query()->dueForDispatch()->get();

        foreach ($due as $announcement) {
            $dispatcher->dispatch($announcement);
        }

        $this->info("Плановые анонсы: отправлено {$due->count()}.");

        return self::SUCCESS;
    }
}
