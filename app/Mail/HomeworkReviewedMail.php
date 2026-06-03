<?php

namespace App\Mail;

use App\Models\HomeworkComment;
use App\Models\HomeworkSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class HomeworkReviewedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $submission;

    public $review;

    public $lessonUrl;

    public $accepted;

    public function __construct(HomeworkSubmission $submission, HomeworkComment $review, string $lessonUrl)
    {
        $this->submission = $submission->loadMissing(['lesson', 'course']);
        $this->review = $review;
        $this->lessonUrl = $lessonUrl;
        $this->accepted = $submission->status === HomeworkSubmission::STATUS_ACCEPTED;
        $this->onQueue('mailing');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->accepted
                ? 'Ваша домашняя работа принята ✅'
                : 'По домашней работе есть замечания ✍️',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.homework.reviewed',
        );
    }
}
