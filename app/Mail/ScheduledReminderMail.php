<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Письмо для разового напоминания студенту, поставленного куратором в
 * ScheduledReminder (например, попросить отзыв о курсе после его возвращения
 * из поездки). Переиспользует вёрстку emails.debtor-reminder — тот же
 * фирменный шаблон «текст + оранжевая шапка».
 */
class ScheduledReminderMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $bodyText,
        public ?string $recipientName = null,
    ) {
        $this->onQueue('mailing');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Напоминание — Общество ревнителей санскрита',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.debtor-reminder',
            with: [
                'bodyText' => $this->bodyText,
                'recipientName' => $this->recipientName,
            ],
        );
    }
}
