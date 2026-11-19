<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Lesson;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * «Урок мини-курса открыт» — письмо дрипа «Подготовительной группы»:
 * урок открылся для студента (расписание «раз в 3 дня»), зовём в кабинет.
 */
class MiniCourseLessonOpenedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public Lesson $lesson,
        public string $courseTitle,
        public string $courseUrl,
    ) {
        $this->onQueue('mailing');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Открыт урок: '.$this->lesson->title.' — мини-курс санскрита',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.mini-course-lesson-opened',
        );
    }
}
