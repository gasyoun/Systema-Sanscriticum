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
 * Подтверждение студенту: заявка «заплатил преподавателю напрямую» получена
 * (H4627). Доступ откроется после сверки куратора по выписке преподавателя —
 * авто-доверия в этом канале нет. Очередь mailing — приём заявки не
 * блокируется на SMTP.
 */
class TeacherPayStudentAckMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Payment $payment)
    {
        $this->onQueue('mailing');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Заявка получена — сверяем вашу оплату преподавателю',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.teacher.claim-student-ack',
            with: ['payment' => $this->payment],
        );
    }
}
