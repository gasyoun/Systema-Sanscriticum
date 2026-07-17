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
 * Письмо 0 марафона (H1148): welcome сразу после регистрации.
 * Текст рулен (H1067, marketing/marathon-2026-08/marathon-email-sequence.md) —
 * не редактировать при изменениях кода. Канал НЕ подключен: отправка ждет
 * ESP-гейта (H1147); Telegram-дрип остается основным.
 */
class MarathonWelcomeMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $tgLink,
        public string $host,
    ) {
        $this->onQueue('mailing');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Вы записаны. Как устроены ваши три дня',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.marathon.welcome',
        );
    }
}
