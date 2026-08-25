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
 * Подтверждение студенту: банковская заявка получена (H3497). Два варианта
 * копии: гость — заявка ушла на ручную сверку; свой (зеркало рулинга
 * 22-08-2026) — доступ уже открыт, сверка будет выборочной. Очередь mailing —
 * прием заявки не блокируется на SMTP.
 */
class BankClaimStudentAckMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Payment $payment)
    {
        $this->onQueue('mailing');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->payment->isAutoTrustedBankClaim()
                ? 'Заявка получена — доступ открыт'
                : 'Заявка получена — сверяем ваш банковский перевод',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.bank.claim-student-ack',
            with: [
                'payment' => $this->payment,
                'trusted' => $this->payment->isAutoTrustedBankClaim(),
            ],
        );
    }
}
