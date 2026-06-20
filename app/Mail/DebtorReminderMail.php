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
 * Письмо-напоминание должнику об оплате, отправляемое со страницы «Должники»
 * (точечно или массово). Тема и тело задаются менеджером, плейсхолдеры
 * ({name}/{course}/{block}/{pay_link}) уже подставлены вызывающим кодом.
 *
 * Транзакционное: НЕ зависит от wants_email_announcements (в отличие от анонсов).
 */
class DebtorReminderMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $subjectLine,
        public string $bodyText,
        public ?string $recipientName = null,
    ) {
        $this->onQueue('mailing');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectLine,
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
