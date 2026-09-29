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
 * H4966: письмо-приглашение в кабинет с multi-day invite-ссылкой (см.
 * SendCabinetInvites::INVITE_PURPOSE) — заменяет 60-минутную PasswordResetMail
 * на email-ветке SendCabinetInvites, которая теряла 84.4% приглашённых.
 */
class CabinetInviteMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public User $user;

    public string $bodyText;

    public function __construct(User $user, string $bodyText)
    {
        $this->user = $user;
        $this->bodyText = $bodyText;
        $this->onQueue('mailing');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Доступ в личный кабинет — Общество ревнителей санскрита',
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: nl2br(e($this->bodyText)),
        );
    }
}
