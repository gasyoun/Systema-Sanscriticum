<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\Unsubscribable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * H1449 B6 — the generic Mailable every Campaign send uses; the per-recipient
 * body (already rewritten with tracking by CampaignHtmlRenderer) is rendered
 * raw, matching the repo idiom of a dedicated Mailable per send type.
 */
class CampaignMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;
    use Unsubscribable;

    public function __construct(
        public string $subjectLine,
        public string $renderedBodyHtml,
    ) {
        $this->onQueue('mailing');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        // 152-ФЗ / 38-ФЗ: готовый HTML кампании без шаблона — подвал отписки дописываем здесь.
        return new Content(htmlString: $this->renderedBodyHtml.view('emails.partials.unsubscribe-footer', [
            'unsubscribeUrl' => $this->unsubscribeUrl(),
        ])->render());
    }
}
