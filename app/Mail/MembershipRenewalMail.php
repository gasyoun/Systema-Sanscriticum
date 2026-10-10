<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * H5823 — письмо-напоминание о продлении членства.
 *
 * Текст стадии уже отрендерен демоном (config membership.renewal_texts):
 * письмо — только конверт, домен знаний живёт в конфиге и команде.
 * Синхронно без ShouldQueue: объём — единицы писем в день, очередь не нужна,
 * а факт доставки нужен демону сразу (дедуп-журнал).
 */
class MembershipRenewalMail extends Mailable
{
    public function __construct(
        public User $user,
        public string $text,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Продление членства — samskrte.ru');
    }

    public function content(): Content
    {
        return new Content(text: 'emails.plain', with: ['text' => $this->text]);
    }
}
