<?php

namespace App\Mail;

use App\Models\HomeworkSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class HomeworkSubmittedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $submission;

    public $reviewUrl;

    public $isResubmission;

    public function __construct(HomeworkSubmission $submission, string $reviewUrl, bool $isResubmission = false)
    {
        $this->submission = $submission->loadMissing(['user', 'lesson', 'course']);
        $this->reviewUrl = $reviewUrl;
        $this->isResubmission = $isResubmission;
        $this->onQueue('mailing');
    }

    public function envelope(): Envelope
    {
        $course = $this->submission->course?->title ?? '—';
        $student = $this->submission->user?->name ?? 'Студент';
        $stage = $this->isResubmission ? '🔁 Пересдача (доработка)' : '📝 Новая работа';

        return new Envelope(
            subject: "{$stage} · {$course} · {$student}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.homework.submitted',
        );
    }
}
