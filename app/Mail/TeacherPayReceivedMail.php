<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Письмо администратору о новой заявке «я заплатил преподавателю напрямую»
 * (H4627). Требует ручной сверки по выписке преподавателя и перевода
 * платежа в paid из админки — после подтверждения номинал вычтется из
 * гонорара преподавателя автоматически (H4597). Очередное (ShouldQueue) —
 * чтобы приём заявки не блокировался на SMTP.
 */
class TeacherPayReceivedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Payment $payment) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Новая заявка: оплата напрямую преподавателю — требует сверки',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.teacher.claim-received',
            with: ['payment' => $this->payment],
        );
    }
}
