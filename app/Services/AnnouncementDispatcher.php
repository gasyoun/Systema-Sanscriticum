<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\SendMessengerAlerts;
use App\Mail\AnnouncementMail;
use App\Models\Announcement;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

/**
 * Единая точка рассылки анонса (email/Telegram/VK) — извлечена из
 * CreateAnnouncement::afterCreate() (H816/S6), чтобы «отправить сейчас» и
 * «отправить по расписанию» (announcements:dispatch-due) не дублировали
 * логику выбора получателей и сборки текста. Идемпотентна через
 * Announcement.dispatched_at — повторный вызов на уже разосланном анонсе
 * ничего не делает.
 */
class AnnouncementDispatcher
{
    public function dispatch(Announcement $announcement): void
    {
        if ($announcement->dispatched_at !== null) {
            return;
        }

        if ($announcement->send_to_email || $announcement->send_to_telegram || $announcement->send_to_vk) {
            $users = $this->recipients($announcement);
            $messageText = $this->messageText($announcement);
            $imagePath = $announcement->image_path;

            foreach ($users as $user) {
                if ($announcement->send_to_email && $user->wants_email_announcements && filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
                    Mail::to($user->email)->queue(new AnnouncementMail($announcement, $user));
                }

                if ($announcement->send_to_telegram || $announcement->send_to_vk) {
                    SendMessengerAlerts::dispatch(
                        $user,
                        $messageText,
                        $announcement->send_to_telegram,
                        $announcement->send_to_vk,
                        $imagePath
                    );
                }
            }
        }

        $announcement->update(['dispatched_at' => now()]);
    }

    /**
     * @return Collection<int, User>
     */
    private function recipients(Announcement $announcement): Collection
    {
        if (empty($announcement->target_courses)) {
            return User::all();
        }

        return User::whereHas('groups', function ($query) use ($announcement) {
            $query->whereIn('groups.id', $announcement->target_courses);
        })->get();
    }

    private function messageText(Announcement $announcement): string
    {
        $rawContent = $announcement->content ?? '';

        if (! empty($announcement->button_url) && ! empty($announcement->button_text)) {
            $rawContent .= '<br><br><a href="'.$announcement->button_url.'">'.$announcement->button_text.'</a>';
        }

        return '<b>🔔 '.$announcement->title.'</b><br><br>'.$rawContent;
    }
}
