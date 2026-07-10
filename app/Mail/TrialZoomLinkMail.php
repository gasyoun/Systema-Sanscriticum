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
 * Письмо купившему пробное занятие. Для предстоящего события — ссылка на Zoom;
 * для прошедшего ($isRecording) — указание, что запись открыта в личном кабинете.
 * Дата и ссылка берутся из события расписания курса (Course::trialSchedule).
 */
class TrialZoomLinkMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public User $user;

    public Course $course;

    public ?string $zoomLink;

    public ?Carbon $startsAt;

    public bool $isRecording;

    public function __construct(User $user, Course $course, ?string $zoomLink, ?Carbon $startsAt, bool $isRecording = false)
    {
        $this->user = $user;
        $this->course = $course;
        $this->zoomLink = $zoomLink;
        $this->startsAt = $startsAt;
        $this->isRecording = $isRecording;
        $this->onQueue('mailing');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->isRecording
                ? 'Пробное занятие оплачено — запись доступна 🎟'
                : 'Пробное занятие оплачено — ссылка для подключения 🎟',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.trial.zoom-link',
        );
    }
}
