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
 * Письмо 1 марафона (H1148): напоминание Дня 1 — зарегистрирован, День 1 не
 * открыт к вечеру первых суток. Текст рулен (H1067); канал инертен до ESP-гейта.
 */
class MarathonDay1Mail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $link,
    ) {
        $this->onQueue('mailing');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'День 1: санскрит роднее, чем кажется',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.marathon.day1',
        );
    }
}
