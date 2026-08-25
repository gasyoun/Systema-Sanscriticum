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
 * Письмо администратору о новой банковской заявке студента (SEPA/SWIFT,
 * H3497). Требует ручной сверки поступления по выписке получателя и перевода
 * платежа в paid из админки. Очередное (ShouldQueue) — чтобы приём заявки не
 * блокировался на SMTP.
 */
class BankClaimReceivedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Payment $payment) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Новая заявка на оплату банковским переводом — требует сверки',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.bank.claim-received',
            with: ['payment' => $this->payment],
        );
    }
}
