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
 * Письмо 2 марафона (H1148): напоминание Дня 2 — День 1 пройден, наступил
 * личный День 2. Текст рулен (H1067); канал инертен до ESP-гейта.
 */
class MarathonDay2Mail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $link,
        public string $host,
    ) {
        $this->onQueue('mailing');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'День 2: как устроено санскритское слово',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.marathon.day2',
        );
    }
}
