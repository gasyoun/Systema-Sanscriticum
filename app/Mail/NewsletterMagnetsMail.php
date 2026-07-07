<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\SubscriberMagnet;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * Письмо подписчику рассылки (H324): одноразовая magic-ссылка в кабинет +
 * список бесплатных лид-магнитов. Основной канал доставки бонусов (бот — опция).
 */
class NewsletterMagnetsMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  Collection<int, SubscriberMagnet>  $magnets
     */
    public function __construct(
        public string $userName,
        public string $magicUrl,
        public Collection $magnets,
    ) {
        $this->onQueue('mailing');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Ваши бонусы и вход в кабинет 🎁',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.newsletter.magnets',
        );
    }
}
