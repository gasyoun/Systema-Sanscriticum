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
 * Подтверждение почты для кабинета, созданного через /кабинет: одна ссылка
 * гасит токен, ставит email_verified_at и сразу пускает в кабинет.
 */
class CabinetEmailVerificationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $userName,
        public string $verifyUrl,
    ) {
        $this->onQueue('mailing');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Подтвердите почту и войдите в кабинет');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.cabinet.verify');
    }
}
