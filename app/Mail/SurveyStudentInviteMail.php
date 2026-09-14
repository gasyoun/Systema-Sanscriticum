<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Личное email-приглашение текущего ученика в волну опроса (H4431) —
 * адресатам, которых бот-канал достать не может (без telegram_id).
 *
 * Отправляется СИНХРОННО (без ShouldQueue): команде нужен статус каждого
 * адресата сразу, чтобы писать его в survey_invitations. Текст тот же, что
 * у Telegram-версии: 15–20 минут, добровольно, без обещания награды.
 */
class SurveyStudentInviteMail extends Mailable
{
    public function __construct(
        public User $user,
        public string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Небольшая анкета о вашем пути — 15–20 минут',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.survey.student-invite',
        );
    }
}
