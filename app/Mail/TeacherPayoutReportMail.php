<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\TeacherPayout;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Прозрачный отчёт преподавателю о расчёте выплаты по блоку: из чего сложилась
 * сумма (студенты × ставка = ₽), курс PayPal и итог в валюте. Отправляется по
 * галочке при создании выплаты из калькулятора (TeacherSalaries).
 *
 * Передаём id выплаты — SerializesModels подтянет свежую запись на момент отправки.
 */
class TeacherPayoutReportMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public int $payoutId)
    {
        $this->onQueue('mailing');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Расчет выплаты за блок');
    }

    public function content(): Content
    {
        $payout = TeacherPayout::with(['teacher', 'course'])->find($this->payoutId);

        return new Content(
            view: 'emails.teacher-payout-report',
            with: ['payout' => $payout],
        );
    }
}
