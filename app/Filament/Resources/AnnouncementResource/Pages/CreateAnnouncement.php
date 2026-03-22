<?php

namespace App\Filament\Resources\AnnouncementResource\Pages;

use App\Filament\Resources\AnnouncementResource;
use App\Mail\AnnouncementMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Filament\Resources\Pages\CreateRecord;

class CreateAnnouncement extends CreateRecord
{
    protected static string $resource = AnnouncementResource::class;

    protected function afterCreate(): void
    {
        $announcement = $this->record;

        // Если НИ ОДНА галочка рассылки не стоит — просто сохраняем в кабинет и выходим
        if (!$announcement->send_to_email && !$announcement->send_to_telegram && !$announcement->send_to_vk) {
            return;
        }

        // Выбираем кому отправлять
        if (empty($announcement->target_courses)) { 
            $users = User::all();
        } else {
            // Ищем юзеров, у которых есть доступ к выбранным курсам (группам)
            $users = User::whereHas('groups', function ($query) use ($announcement) {
                $query->whereIn('groups.id', $announcement->target_courses);
            })->get();
        }

        // === 1. ГОТОВИМ ТЕКСТ (БЕЗ STRIP_TAGS!) ===
        $rawContent = $announcement->content ?? '';

        // Если админ заполнил кнопку — добавляем её в конец текста как обычную HTML-ссылку.
        // Наш умный Job сам превратит её в красивую ссылку для ТГ и ВК!
        if (!empty($announcement->button_url) && !empty($announcement->button_text)) {
            $rawContent .= '<br><br><a href="' . $announcement->button_url . '">' . $announcement->button_text . '</a>';
        }

        // Собираем текст с заголовком (используем HTML-переносы <br>, Job их сам обработает)
        $messageText = "<b>🔔 " . $announcement->title . "</b><br><br>" . $rawContent;


        // === 2. ДОСТАЕМ КАРТИНКУ (Берем просто путь в системе) ===
        $imagePath = $announcement->image_path ?? null;

        // === 3. РАСПРЕДЕЛЯЕМ ЗАДАЧИ ===
        foreach ($users as $user) {
            // 1. Отправляем на Email
            if ($announcement->send_to_email && filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
                Mail::to($user->email)->queue(new AnnouncementMail($announcement, $user));
            }
            
            // 2. Отправляем в мессенджеры
            if ($announcement->send_to_telegram || $announcement->send_to_vk) {
                // Передаем в Job сырой текст и ПУТЬ к картинке (не URL)
                \App\Jobs\SendMessengerAlerts::dispatch(
                    $user, 
                    $messageText, 
                    $announcement->send_to_telegram, 
                    $announcement->send_to_vk,
                    $imagePath // Передаем путь
                );
            }
        }
    }
}