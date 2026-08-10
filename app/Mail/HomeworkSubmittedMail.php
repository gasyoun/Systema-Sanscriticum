<?php

namespace App\Mail;

use App\Models\HomeworkSubmission;
use App\Services\HomeworkImagePdfService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class HomeworkSubmittedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $submission;

    public $reviewUrl;

    public $isResubmission;

    /** Есть ли PDF-картинок в приложении (для текста письма). */
    public bool $hasImagesPdfAttachment = false;

    public function __construct(HomeworkSubmission $submission, string $reviewUrl, bool $isResubmission = false)
    {
        $this->submission = $submission->loadMissing(['user', 'lesson', 'course']);
        $this->reviewUrl = $reviewUrl;
        $this->isResubmission = $isResubmission;
        $this->hasImagesPdfAttachment = app(HomeworkImagePdfService::class)
            ->canAttachToMail($this->submission);
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

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $pdf = app(HomeworkImagePdfService::class);
        if (! $pdf->canAttachToMail($this->submission)) {
            return [];
        }

        return [
            Attachment::fromStorageDisk(
                HomeworkImagePdfService::DISK,
                $pdf->pathFor($this->submission),
            )
                ->as($pdf->downloadName($this->submission))
                ->withMime('application/pdf'),
        ];
    }
}
