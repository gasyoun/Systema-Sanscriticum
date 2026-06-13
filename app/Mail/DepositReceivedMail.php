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
 * Подтверждение брони курса (депозит/предоплата) по e-mail.
 * Нужно, чтобы повторные студенты без Telegram тоже получали подтверждение.
 * Ссылка на чат курса — из Course::chat_url (у каждого курса своя).
 */
class DepositReceivedMail extends Mailable implements ShouldQueue
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
            subject: 'Бронь курса принята 📌',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.deposit.received',
        );
    }
}
