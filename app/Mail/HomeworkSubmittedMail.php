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

    public function __construct(HomeworkSubmission $submission, string $reviewUrl)
    {
        $this->submission = $submission->loadMissing(['user', 'lesson', 'course']);
        $this->reviewUrl = $reviewUrl;
        $this->onQueue('mailing');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Новая домашняя работа на проверку 📝',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.homework.submitted',
        );
    }
}
