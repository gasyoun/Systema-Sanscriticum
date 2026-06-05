<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Письмо-приглашение преподавателю в учебную панель (/admin).
 *
 * Зеркалит StudentWelcomeMail: ShouldQueue → уходит в фоне на очередь «mailing»,
 * чтобы админ не ждал отправки при сохранении карточки преподавателя.
 *
 * $password передаётся в открытом виде, только когда система задаёт пароль
 * (новый аккаунт или явный сброс админом). Если пароль не менялся — null,
 * и шаблон предложит войти текущим паролем либо восстановить его.
 */
class TeacherInviteMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public User $user;

    public ?string $password;

    public string $panelUrl;

    public function __construct(User $user, ?string $password = null)
    {
        $this->user = $user;
        $this->password = $password;
        $this->panelUrl = url('/admin');
        $this->onQueue('mailing');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Приглашение в преподавательскую панель Академии 🙏',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.teacher.invite',
        );
    }
}
