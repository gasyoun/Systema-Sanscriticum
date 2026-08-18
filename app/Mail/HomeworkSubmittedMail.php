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

    public function __construct(HomeworkSubmission $submission, string $reviewUrl, bool $isResubmission = false)
    {
        $this->submission = $submission->loadMissing(['user', 'lesson', 'course']);
        $this->reviewUrl = $reviewUrl;
        $this->isResubmission = $isResubmission;
        $this->onQueue('mailing');
    }

    /**
     * Есть ли PDF картинок в приложении (для текста письма).
     *
     * Считается ЛЕНИВО — на воркере в момент отправки, вместе с
     * `attachments()`, а не в конструкторе на пути запроса (H3095). Раньше
     * конструктор был единственным, что требовало готового PDF в момент
     * постановки письма в очередь, и тем самым привязывал уведомление к
     * сборке. Теперь сборка идёт своей очередью (`BuildHomeworkImagesPdfJob`),
     * а письмо просто смотрит, успел PDF или нет: успел — вложение и строка о
     * нём, не успел — только ссылка «Проверить работу» (по ней PDF досбирается
     * лениво в `HomeworkController::downloadImagesPdf()`).
     */
    public function hasImagesPdfAttachment(): bool
    {
        return app(HomeworkImagePdfService::class)->canAttachToMail($this->submission);
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
        // `content()` вызывается при рендере на воркере — здесь ленивый флаг
        // уже знает, успела ли сборка PDF.
        return new Content(
            view: 'emails.homework.submitted',
            with: ['hasImagesPdfAttachment' => $this->hasImagesPdfAttachment()],
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
