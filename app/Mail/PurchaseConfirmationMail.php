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
 * Подтверждение покупки — чек и приветствие в одном письме (H1286).
 * Уходит на КАЖДУЮ реальную (не conditional) успешную оплату курса — в отличие
 * от CourseWelcomeMail (первая оплата конкретного курса, чат) и
 * StudentWelcomeMail (самый первый вход, пароль). Содержит: что куплено,
 * сколько это стоило, когда откроется доступ, с чего начать, куда писать.
 * Кассовый чек формирует платёжная система — письмо на него ссылается,
 * но не воспроизводит.
 */
class PurchaseConfirmationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Payment $payment,
    ) {
        $this->onQueue('mailing');
    }

    public function envelope(): Envelope
    {
        $title = $this->payment->course->title ?? null;

        return new Envelope(
            subject: $title !== null
                ? 'Оплата получена — «'.$title.'»'
                : 'Оплата получена',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.purchase-confirmation',
            with: [
                'user' => $this->payment->user,
                'course' => $this->payment->course,
                'tariffScope' => $this->tariffScope(),
                'formattedAmount' => $this->formattedAmount(),
            ],
        );
    }

    /**
     * Человекочитаемый объём покупки: «полный курс», «блок 2», «блоки 1–3».
     * Null — когда объём из платежа не восстановить (ручной платёж без тарифа):
     * строка тарифа в письме просто не выводится, чек не врёт.
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

    /**
     * Сумма в рублях без копеек, с пробелом-разделителем тысяч.
     * Null при нулевой оплате (бесплатный доступ, 100%-промокод) — строка суммы
     * не выводится вовсе: «0 ₽» в чеке читается как ошибка, а не как факт.
     */
    private function formattedAmount(): ?string
    {
        $amount = (float) $this->payment->amount;

        if ($amount <= 0) {
            return null;
        }

        return number_format($amount, 0, '.', ' ').' ₽';
    }
}
