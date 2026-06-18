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
 * Благодарность за первую оплату конкретного курса (со ссылкой на чат курса).
 * Уходит при первой реальной оплате именно этого курса — в отличие от
 * StudentWelcomeMail (она про самый первый вход в Академию). У вернувшегося
 * студента, который берёт 2-й/3-й курс, StudentWelcomeMail уже не сработает,
 * а это письмо — да. Ссылка на чат — из Course::chat_url (у каждого курса своя).
 */
class CourseWelcomeMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public Course $course,
    ) {
        $this->onQueue('mailing');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Спасибо за оплату курса «'.$this->course->title.'» 🙏',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.course.welcome',
        );
    }
}
