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

class GasunsPayReceivedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Payment $payment) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Новая заявка: перевод рублями Гасунсу — требует сверки',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.gasuns.claim-received',
            with: ['payment' => $this->payment],
        );
    }
}
