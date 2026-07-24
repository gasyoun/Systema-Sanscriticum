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
 * One-shot content digest (H1548, Wave 2, D14) — a minimal mailer scaffold
 * for the `email_blast` ContentCandidate type. Sends pre-composed
 * EmailBlastComposer output only; no campaign/segment domain model beyond
 * the candidate itself (does not replace H1449's campaign engine). Gated
 * OFF by `content_email_oneshot` until the August SMTP activation.
 */
class ContentDigestMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $digestTitle,
        public string $digestBody,
    ) {
        $this->onQueue('mailing');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->digestTitle,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.content.digest',
        );
    }
}
