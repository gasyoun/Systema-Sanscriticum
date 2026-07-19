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
 * День 1 онбординга: «с чего начать» — назавтра после покупки курса (H1286).
 * Email-канал инертен до починки прод-SMTP (#504, ESP-гейт H1147); рабочая
 * доставка дня 1 сегодня — Telegram/VK через ScheduledReminder
 * (Payment::scheduleOnboardingIfFirstForCourse). Send-сайта у письма
 * сознательно нет — как у марафонских Mailable.
 */
class OnboardingDay1Mail extends Mailable implements ShouldQueue
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
            subject: 'С чего начать: первый урок уже открыт',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.onboarding.day1',
        );
    }
}
