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

/**
 * Подтверждение студенту: PayPal-заявка получена (H1292). Два варианта копии:
 * гость — заявка ушла на ручную сверку; свой (ruling 22-08-2026) — доступ уже
 * открыт, сверка будет выборочной. Зеркало админского PaypalClaimReceivedMail
 * для самого студента. Обещания письма синхронизированы с флешем
 * PaypalClaimController::store и docs/copy/money-diaspora-paypal-buyer-path.md.
 * Очередь mailing — как у PurchaseConfirmationMail; прием заявки не блокируется
 * на SMTP.
 */
class PaypalClaimStudentAckMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Payment $payment)
    {
        $this->onQueue('mailing');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->payment->isAutoTrustedPaypal()
                ? 'Заявка получена — доступ открыт'
                : 'Заявка получена — сверяем ваш платеж PayPal',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.paypal.claim-student-ack',
            with: [
                'user' => $this->payment->user,
                'course' => $this->payment->course,
                'tariffScope' => $this->tariffScope(),
                'claimedAmount' => $this->payment->foreignAmountLabel() ?: null,
                // Ruling 22-08-2026: своим — доступ сразу, гостям — ручная сверка.
                'trusted' => $this->payment->isAutoTrustedPaypal(),
            ],
        );
    }

    /**
     * Человекочитаемый объем покупки: «полный курс», «блок 2», «блоки 1–3».
     * Копия tariffScope из PurchaseConfirmationMail — а не operationLabel(),
     * который для служебных типов платежей добавляет эмодзи (в транзакционном
     * письме студенту эмодзи запрещены контрактом голоса). Null — объем из
     * платежа не восстановить: строка тарифа просто не выводится.
     */
    private function tariffScope(): ?string
    {
        $payment = $this->payment;

        if ($payment->tariff === 'full') {
            return 'полный курс';
        }

        if ($payment->start_block && $payment->end_block
            && (int) $payment->end_block > (int) $payment->start_block) {
            return 'блоки '.$payment->start_block.'–'.$payment->end_block;
        }

        $blockNo = $payment->start_block
            ?: preg_replace('/[^\d]+/', '', (string) $payment->tariff);

        return $blockNo !== '' && $blockNo !== null ? 'блок '.$blockNo : null;
    }
}
