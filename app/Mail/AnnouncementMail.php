<?php

namespace App\Mail;

use App\Models\Announcement;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue; // <--- ЭТО ВАЖНО ДЛЯ ФОНОВОЙ ОТПРАВКИ
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

// Добавляем implements ShouldQueue
class AnnouncementMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $announcement;
    public $user;

    public function __construct(Announcement $announcement, User $user)
    {
        $this->announcement = $announcement;
        $this->user = $user;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->announcement->title, // Заголовок письма берем из админки
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.announcement', // Название шаблона, который мы сейчас сверстаем
        );
    }
}