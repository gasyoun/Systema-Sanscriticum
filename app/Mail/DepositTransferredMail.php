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
 * Подтверждение переноса брони (депозита) на другой курс.
 * Шлётся, когда админ переносит оплаченную бронь студента с курса на курс
 * (PaymentResource action «Перенести бронь»). Ссылка на чат — нового курса.
 */
class DepositTransferredMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public Course $fromCourse,
        public Course $toCourse,
    ) {
        $this->onQueue('mailing');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Бронь перенесена на другой курс 🔄',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.deposit.transferred',
        );
    }
}
