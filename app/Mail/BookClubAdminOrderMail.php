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
 * Письмо администратору о заказе магазина, который требует ручного шага (H8412):
 * самовывоз (согласовать время) и 5+ книг (согласовать сумму). Онлайн-платёж
 * в этих ветках не запускается, поэтому письмо — единственный сигнал.
 */
class BookClubAdminOrderMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public BookClubOrder $order) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Книжный клуб: заказ №'.$this->order->id.' — нужен ручной шаг',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.book-club.admin-order',
            with: ['order' => $this->order],
        );
    }
}
