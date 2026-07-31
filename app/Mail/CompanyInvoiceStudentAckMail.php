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

/** Student ack: invoice issued, wait for bank transfer + manual confirm. */
final class CompanyInvoiceStudentAckMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Payment $payment) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Счет на оплату сформирован — ждем перевод',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.invoice.claim-student-ack',
            with: ['payment' => $this->payment],
        );
    }
}
