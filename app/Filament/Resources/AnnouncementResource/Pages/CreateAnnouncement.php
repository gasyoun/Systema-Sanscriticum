<?php

namespace App\Filament\Resources\AnnouncementResource\Pages;

use App\Filament\Resources\AnnouncementResource;
use App\Services\AnnouncementDispatcher;
use Filament\Resources\Pages\CreateRecord;

class CreateAnnouncement extends CreateRecord
{
    protected static string $resource = AnnouncementResource::class;

    protected function afterCreate(): void
    {
        $announcement = $this->record;

        // Отложенный анонс (scheduled_at в будущем) уйдёт командой
        // announcements:dispatch-due, когда наступит срок. Немедленный
        // (scheduled_at пуст или в прошлом) рассылаем прямо сейчас — тем же
        // диспетчером, что и команда (единый путь, дедуп по dispatched_at).
        if ($announcement->scheduled_at !== null && $announcement->scheduled_at->isFuture()) {
            return;
        }

        app(AnnouncementDispatcher::class)->dispatch($announcement);
    }
}
