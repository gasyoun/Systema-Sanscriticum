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
 * Письмо 4 марафона (H1148): после консультации — запись эфира и купон.
 * Условие: zoom_recording_url проставлен. Текст рулен (H1067); канал инертен
 * до ESP-гейта.
 */
class MarathonRecordingMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $recordingLink,
        public string $coupon,
        public string $host,
    ) {
        $this->onQueue('mailing');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Запись консультации и ваша скидка {$this->coupon} ₽",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.marathon.recording',
        );
    }
}
