<?php

namespace App\Mail;

use App\Models\Course;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Недельная сводка преподавателю курса по работам, которые проверяет
 * проверяющий по гранту (H1729): что сдано, что проверено, что ещё ждёт.
 * Заменяет письмо на каждую работу — но только для групп с проверяющим.
 */
class HomeworkReviewerDigestMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public Course $course;

    /** @var array{submitted:int, reviewed:int, waiting:int, waiting_rows:array<int, array<string, string>>, reviewers:string} */
    public array $summary;

    public string $queueUrl;

    /**
     * @param  array{submitted:int, reviewed:int, waiting:int, waiting_rows:array<int, array<string, string>>, reviewers:string}  $summary
     */
    public function __construct(Course $course, array $summary, string $queueUrl)
    {
        $this->course = $course;
        $this->summary = $summary;
        $this->queueUrl = $queueUrl;
        $this->onQueue('mailing');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '📊 Домашки за неделю · '.($this->course->title ?? '—'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.homework.reviewer-digest',
        );
    }
}
