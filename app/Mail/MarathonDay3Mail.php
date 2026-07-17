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
 * Письмо 3 марафона (H1148): живая консультация Дня 3. Два рулевых варианта
 * по треку (marathon_enrollments.paid_at): 3а платный «с проверкой», 3б
 * бесплатный (участие в записи). Текст рулен (H1067); канал инертен до
 * ESP-гейта.
 */
class MarathonDay3Mail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public bool $paid,
        public string $date,
        public string $host,
        public string $link,
    ) {
        $this->onQueue('mailing');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->paid
                ? 'Завтра живая консультация — ваш вопрос уже у ведущего'
                : "Живая консультация {$this->date} — запись пришлем сюда",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.marathon.day3',
        );
    }
}
