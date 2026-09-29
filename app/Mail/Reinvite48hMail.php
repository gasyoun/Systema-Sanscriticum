<?php

declare(strict_types=1);

namespace App\Mail;

use App\Console\Commands\SendPaidNeverLoginReinvite;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * H5022 — повторное приглашение в кабинет через 48 ч после оплаты без входа
 * (students:reinvite-48h), email-ветка (когда Telegram не привязан).
 * Транзакционное письмо: `wants_email_announcements` не проверяется.
 * Magic-ссылка одноразовая, TTL — {@see SendPaidNeverLoginReinvite::LINK_TTL_HOURS}.
 */
class Reinvite48hMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $loginUrl,
    ) {
        $this->onQueue('mailing');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Ваш кабинет уже открыт: записи занятий ждут',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.onboarding.reinvite-48h',
        );
    }
}
