<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Произвольное письмо лиду, отправляемое менеджером из админки
 * (action «Письмо» в LeadResource). Тема и тело задаются вручную.
 */
class LeadAdHocMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $subjectLine,
        public string $bodyText,
        public ?string $leadName = null,
    ) {
        $this->onQueue('mailing');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.lead.ad-hoc',
            with: [
                'bodyText' => $this->bodyText,
                'leadName' => $this->leadName,
            ],
        );
    }
}
