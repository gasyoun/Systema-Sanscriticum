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
 * Подтверждение студенту: PayPal-заявка получена и ушла на ручную сверку (H1292).
 * Зеркало админского PaypalClaimReceivedMail для самого студента — до этого
 * письма подавший заявку не получал ничего и не знал, дошла ли она. Обещания
 * письма (сверка «обычно в течение одного рабочего дня», пароль для нового
 * аккаунта) синхронизированы с флешем PaypalClaimController::store и
 * docs/copy/money-diaspora-paypal-buyer-path.md. Очередь mailing — как у
 * PurchaseConfirmationMail; прием заявки не блокируется на SMTP.
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
            subject: 'Заявка получена — сверяем ваш платеж PayPal',
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
