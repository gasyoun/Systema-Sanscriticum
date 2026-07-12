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

        // Плановая отправка (H816/S6): будущая scheduled_at откладывает рассылку на
        // announcements:dispatch-due, вместо немедленной отправки при создании.
        if ($announcement->scheduled_at !== null && $announcement->scheduled_at->isFuture()) {
            return;
        }

        app(AnnouncementDispatcher::class)->dispatch($announcement);
    }
}
