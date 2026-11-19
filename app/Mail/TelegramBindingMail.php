<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Уведомление о привязке Telegram к кабинету (H8294, «вариант B», MG 14-11-2026).
 * Уходит на email аккаунта при каждой привязке из чата бот-а (email заказа →
 * привязка): владелец узнаёт о событии, даже если его Telegram не при нём.
 *
 * Транзакционное письмо: `wants_email_announcements` не проверяется —
 * это щит безопасности, не рассылка.
 */
class TelegramBindingMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public User $user;

    public ?string $boundUsername;

    public function __construct(User $user, ?string $boundUsername)
    {
        $this->user = $user;
        $this->boundUsername = $boundUsername;
        $this->onQueue('mailing');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'К вашему кабинету привязан Telegram',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.auth.telegram-binding',
        );
    }
}
