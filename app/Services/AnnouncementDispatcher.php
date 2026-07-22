<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\SendMessengerAlerts;
use App\Mail\AnnouncementMail;
use App\Models\Announcement;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/**
 * Единая точка рассылки анонса по выбранным каналам (email/Telegram/VK). Раньше
 * этот код жил ТОЛЬКО в CreateAnnouncement::afterCreate и срабатывал синхронно
 * при создании — отсюда 16-часовой аврал (H816 PR 2). Теперь его переиспользуют
 * оба пути: немедленная отправка при создании и отложенная команда
 * announcements:dispatch-due по scheduled_at.
 *
 * Идемпотентность — по dispatched_at: повторный вызов на уже разосланном анонсе
 * ничего не делает (команда работает каждые 5 минут, дедуп обязателен).
 */
class AnnouncementDispatcher
{
    public function dispatch(Announcement $announcement): void
    {
        if ($announcement->dispatched_at !== null || ! $announcement->is_published) {
            return;
        }

        if (! $announcement->send_to_email && ! $announcement->send_to_telegram && ! $announcement->send_to_vk) {
            // Ни один канал не выбран — анонс живёт только в кабинете; рассылать
            // нечего. dispatched_at НЕ ставим (нечего было слать).
            return;
        }

        foreach ($this->recipients($announcement) as $user) {
            if ($announcement->send_to_email && $user->wants_email_announcements && filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
                Mail::to($user->email)->queue(new AnnouncementMail($announcement, $user));
            }

            // 152-ФЗ: рекламная рассылка в мессенджеры — только с согласием
            // (зеркало email-гейта выше). Транзакционные уведомления идут
            // мимо диспетчера анонсов и этим флагом НЕ ограничены.
            if (($announcement->send_to_telegram || $announcement->send_to_vk) && $user->wants_messenger_announcements) {
                SendMessengerAlerts::dispatch(
                    $user,
                    $this->messageText($announcement),
                    $announcement->send_to_telegram,
                    $announcement->send_to_vk,
                    $announcement->image_path ?? null,
                );
            }
        }

        $announcement->forceFill(['dispatched_at' => now()])->save();
    }

    /** Аудитория: все пользователи, либо только состоящие в целевых группах. */
    private function recipients(Announcement $announcement)
    {
        if (empty($announcement->target_courses)) {
            return User::all();
        }

        return User::whereHas('groups', function ($query) use ($announcement) {
            $query->whereIn('groups.id', $announcement->target_courses);
        })->get();
    }

    /** Текст с заголовком и кнопкой (SendMessengerAlerts сам приведёт HTML к TG/VK). */
    private function messageText(Announcement $announcement): string
    {
        $rawContent = $announcement->content ?? '';

        if (! empty($announcement->button_url) && ! empty($announcement->button_text)) {
            $rawContent .= '<br><br><a href="'.$announcement->button_url.'">'.$announcement->button_text.'</a>';
        }

        return '<b>🔔 '.$announcement->title.'</b><br><br>'.$rawContent;
    }
}
