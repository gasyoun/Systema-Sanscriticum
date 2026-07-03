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
 * Письмо администратору о новой PayPal-заявке студента (оплата из-за рубежа).
 * Требует ручной сверки в PayPal и перевода платежа в paid из админки.
 * Очередное (ShouldQueue) — чтобы приём заявки не блокировался на SMTP.
 */
class PaypalClaimReceivedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Payment $payment) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Новая заявка на оплату через PayPal — требует сверки',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.paypal.claim-received',
            with: ['payment' => $this->payment],
        );
    }
}
