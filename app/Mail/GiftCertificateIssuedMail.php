<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\GiftCertificate;
use App\Models\User;
use App\Services\GiftCertificateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachments;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Покупателю подарочного сертификата (H3334): код активации + PDF.
 * ЕДИНСТВЕННОЕ место, где сырой одноразовый код покидает память процесса —
 * ни БД, ни логи его не содержат. Письмо ставится в очередь mailing, как
 * остальные транзакционные письма.
 */
class GiftCertificateIssuedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** Сырой код живёт только в этом инстансе до отправки. */
    public function __construct(
        public User $buyer,
        public GiftCertificate $certificate,
        public string $activationCode,
    ) {
        $this->onQueue('mailing');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Ваш подарочный сертификат и код активации 🎁',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.gift.issued',
            with: [
                'buyer' => $this->buyer,
                'certificate' => $this->certificate,
                'code' => $this->activationCode,
                'activateUrl' => route('gift.activate'),
                'verifyUrl' => route('gift.verify', ['number' => $this->certificate->number]),
            ],
        );
    }

    public function attachments(): Attachments
    {
        // PDF печатается БЕЗ кода: сертификат верифицируется публичным номером,
        // а секретный код остаётся только в теле этого письма.
        $pdf = app(GiftCertificateService::class)->renderPdf($this->certificate);

        return Attachments::fromData(
            fn () => $pdf->output(),
            'gift-certificate-'.$this->certificate->number.'.pdf',
        );
    }
}
