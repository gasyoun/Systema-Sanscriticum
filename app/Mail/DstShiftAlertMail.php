<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * H4434 — email-ветка DST-будильника (для юзеров без telegram_id).
 * Текст собирает RemindDstShifts — тут только конверт и контент.
 */
class DstShiftAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $user, public string $messageText) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Перевод часов: занятие в вашем местном времени сместится',
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'emails.dst-shift-alert',
            with: [
                'messageText' => $this->messageText,
                'user' => $this->user,
            ],
        );
    }
}
