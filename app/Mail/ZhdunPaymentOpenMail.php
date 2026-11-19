<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\Unsubscribable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Ждун: email-канал «оплата открыта» голосовавшему (ZhdunVoterNotifier) —
 * тот же текст, что в TG/VK, плюс кнопка на страницу курса. Призыв к
 * оплате = рекламное письмо: уходит только при wants_email_announcements,
 * с отпиской (Unsubscribable).
 */
class ZhdunPaymentOpenMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;
    use Unsubscribable;

    public function __construct(
        public readonly string $courseTitle,
        public readonly string $text,
        public readonly string $url,
    ) {
        $this->onQueue('mailing');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Оплата открыта: '.$this->courseTitle,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.zhdun.payment-open',
            with: [
                // Текст общий с мессенджерами: там он HTML-экранирован для
                // Telegram, а из админки приходит как есть — приводим к
                // сырому виду и экранируем один раз.
                'bodyHtml' => nl2br(e(html_entity_decode($this->text, ENT_QUOTES | ENT_HTML5, 'UTF-8'))),
                'url' => $this->url,
            ],
        );
    }
}
