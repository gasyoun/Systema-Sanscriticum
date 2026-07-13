<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Announcement;
use App\Services\AnnouncementDispatcher;
use Illuminate\Console\Command;

/**
 * Рассылает запланированные анонсы, у которых наступил scheduled_at (H816 PR 2).
 * Идемпотентность — за dispatched_at (диспетчер ставит его после рассылки,
 * дважды один анонс не уходит). Запуск каждые 5 минут (Kernel::schedule),
 * по образцу classes:remind-upcoming.
 */
final class DispatchDueAnnouncements extends Command
{
    protected $signature = 'announcements:dispatch-due';

    protected $description = 'Разослать запланированные анонсы, у которых наступил scheduled_at';

    public function handle(AnnouncementDispatcher $dispatcher): int
    {
        $due = Announcement::query()
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->whereNull('dispatched_at')
            ->where('is_published', true)
            ->where(function ($query) {
                $query->where('send_to_email', true)
                    ->orWhere('send_to_telegram', true)
                    ->orWhere('send_to_vk', true);
            })
            ->get();

        foreach ($due as $announcement) {
            $dispatcher->dispatch($announcement);
        }

        $this->info("Announcements dispatched: {$due->count()}.");

        return self::SUCCESS;
    }
}
