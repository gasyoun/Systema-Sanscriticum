<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SendTelegramMessageJob;
use App\Models\PaymentPromise;
use Illuminate\Console\Command;

class RemindPaymentPromisesTomorrow extends Command
{
    protected $signature = 'promises:remind-tomorrow';

    protected $description = 'Шлёт студентам напоминание в TG: завтра срок по обещанию/рассрочке.';

    public function handle(): int
    {
        $tomorrow = now()->addDay()->toDateString();

        $promises = PaymentPromise::query()
            ->with('course')
            ->where('status', PaymentPromise::STATUS_ACTIVE)
            ->whereDate('promised_at', $tomorrow)
            ->get();

        $sent = 0;
        $skipped = 0;

        foreach ($promises as $promise) {
            if (! $promise->user_id) {
                $skipped++;

                continue;
            }

            $courseName = $promise->course->title ?? 'курс';
            $url = url('/login');
            $amount = $promise->amount !== null
                ? number_format((float) $promise->amount, 0, '.', ' ').' ₽'
                : 'договорённая сумма';

            $text = "⏰ <b>Завтра срок оплаты</b>\n\n";
            $text .= "Намасте! По договорённости завтра — срок оплаты курса <b>«{$courseName}»</b>";
            $text .= " ({$amount}).\n\n";
            $text .= "Чтобы не потерять доступ — оплатите сегодня:\n";
            $text .= "<a href='{$url}'>Перейти в личный кабинет</a>";

            SendTelegramMessageJob::dispatch($promise->user_id, $text);
            $sent++;
        }

        $this->info("Напоминания: отправлено {$sent}, пропущено {$skipped}");

        return self::SUCCESS;
    }
}
