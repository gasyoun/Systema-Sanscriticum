<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\BookClubOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Письмо покупателю «Книжного клуба» при переходе заказа в shipped (H8412):
 * трек-номер и состав заказа. Очередное — смена статуса в админке не должна
 * ждать SMTP. Шлётся из BookClubOrder::booted() на transition → shipped.
 */
class BookClubOrderShippedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public BookClubOrder $order) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Книга отправлена — «'.config('book_club.book_title').'»',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.book-club.shipped',
            with: ['order' => $this->order],
        );
    }
}
