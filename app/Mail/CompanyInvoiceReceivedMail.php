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

/** Admin: new company invoice claim — reconcile bank transfer, then mark paid. */
final class CompanyInvoiceReceivedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Payment $payment) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Новый счет для юрлица — ждет поступления',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.invoice.claim-received',
            with: ['payment' => $this->payment],
        );
    }
}
