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
 * Письмо лиду со ссылкой на запись прошедшего вебинара.
 * Отправляется, когда админ заполнил у лендинга webinar_recording_url —
 * планировщик webinar:deliver-recordings разошлёт всем лидам лендинга.
 */
class LeadWebinarRecordingMail extends Mailable implements ShouldQueue
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
            subject: "Запись вебинара «{$label}» — смотрите по ссылке",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.lead.webinar-recording',
        );
    }
}
