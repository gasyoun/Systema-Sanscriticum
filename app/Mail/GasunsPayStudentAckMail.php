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

class GasunsPayStudentAckMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Payment $payment, public bool $trusted = false) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Уведомление о переводе получено — сверяем поступление',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.gasuns.claim-ack',
            with: ['payment' => $this->payment],
        );
    }
}
