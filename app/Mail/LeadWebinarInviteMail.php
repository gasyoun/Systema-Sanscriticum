<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\LandingPage;
use App\Models\Lead;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Приветственное письмо лиду со ссылкой на вебинар.
 * Отправляется при создании нового лида с email, если у его лендинга
 * заполнено поле webinar_url. Дата/название берутся из landing_pages.
 */
class LeadWebinarInviteMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Lead $lead,
        public LandingPage $landing,
    ) {
        $this->onQueue('mailing');
    }

    public function envelope(): Envelope
    {
        $label = $this->landing->webinar_label ?: 'вебинар';

        return new Envelope(
            subject: "Вы записаны на {$label} — ссылка для подключения",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.lead.webinar-invite',
        );
    }
}
