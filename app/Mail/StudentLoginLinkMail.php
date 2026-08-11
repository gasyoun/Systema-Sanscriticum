<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User;
use App\Services\Access\LoginLinkNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Одноразовая ссылка для входа, выданная куратором из «Проблем со входом» (H849).
 * Транзакционное письмо: `wants_email_announcements` не проверяется.
 *
 * Пароль сюда не кладётся — см. {@see LoginLinkNotifier}.
 */
class StudentLoginLinkMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public User $user;

    public string $loginUrl;

    public function __construct(User $user, string $loginUrl)
    {
        $this->user = $user;
        $this->loginUrl = $loginUrl;
        $this->onQueue('mailing');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Ссылка для входа в личный кабинет ОРС',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.auth.login-link',
        );
    }
}
