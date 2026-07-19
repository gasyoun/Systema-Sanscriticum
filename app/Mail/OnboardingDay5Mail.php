<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Course;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * День 5 онбординга: «если ещё не начали» — мягкий чек-ин без вины (H1286).
 * Регистр — грейс-нота должникового напоминания («…просто проигнорируйте»):
 * читатель честен, компетентен и занят. Email-канал инертен до починки
 * прод-SMTP (#504); рабочая доставка — Telegram/VK через ScheduledReminder.
 * Send-сайта сознательно нет — как у марафонских Mailable.
 */
class OnboardingDay5Mail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public ?Course $course = null,
    ) {
        $this->onQueue('mailing');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Как идут занятия?',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.onboarding.day5',
        );
    }
}
