<?php

namespace App\Filament\Resources\AnnouncementResource\Pages;

use App\Filament\Resources\AnnouncementResource;
use App\Mail\AnnouncementMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Filament\Resources\Pages\CreateRecord;

class CreateAnnouncement extends CreateRecord
{
    protected static string $resource = AnnouncementResource::class;

    // Срабатывает после нажатия кнопки "Создать" в админке
    protected function afterCreate(): void
    {
        $announcement = $this->record;

        // Если галочка "На Email" НЕ стоит — ничего не делаем
        if (!$announcement->send_to_email) {
            return;
        }

        // Выбираем кому отправлять
        if (empty($announcement->target_groups)) {
            $users = User::all(); // Всем
        } else {
            // Только выбранным группам
            $users = User::whereHas('groups', function ($query) use ($announcement) {
                $query->whereIn('groups.id', $announcement->target_groups);
            })->get();
        }

        // ЖЕСТКО ПУШАЕМ В ОЧЕРЕДЬ (используем ->queue вместо ->send)
        foreach ($users as $user) {
            if (filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
                Mail::to($user->email)->queue(new AnnouncementMail($announcement, $user));
            }
        }
    }
}