<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Быстрая запись на мини-курс уже существующим email: одноразовая ссылка
 * «войти и начать» (MiniCourseQuickEnrollController::MAGIC_PURPOSE, 24 ч).
 */
class MiniCourseMagicLinkMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $courseTitle,
        public string $magicUrl,
    ) {
        $this->onQueue('mailing');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Ваша ссылка на бесплатный мини-курс санскрита',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.mini-course-magic-link',
        );
    }
}
