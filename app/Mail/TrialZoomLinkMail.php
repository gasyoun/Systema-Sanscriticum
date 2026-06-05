<?php

namespace App\Mail;

use App\Models\Course;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Письмо со ссылкой на Zoom для купившего пробное живое занятие.
 * Дата и ссылка берутся из события расписания курса (Course::trialSchedule).
 */
class TrialZoomLinkMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public User $user;

    public Course $course;

    public ?string $zoomLink;

    public ?Carbon $startsAt;

    public function __construct(User $user, Course $course, ?string $zoomLink, ?Carbon $startsAt)
    {
        $this->user = $user;
        $this->course = $course;
        $this->zoomLink = $zoomLink;
        $this->startsAt = $startsAt;
        $this->onQueue('mailing');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Пробное занятие оплачено — ссылка для подключения 🎟',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.trial.zoom-link',
        );
    }
}
