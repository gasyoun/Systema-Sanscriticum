<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\IssueDigitalKassaReceiptJob;
use App\Models\FiscalReceipt;
use App\Models\Payment;
use Illuminate\Console\Command;

/**
 * Подстраховка чеков Digital Kassa.
 *
 *  - без опций (scheduler, ежечасно): переставить в очередь оплаченные чеки,
 *    застрявшие в pending/processing дольше --stale-minutes (потерянная джоба);
 *  - --payment=ID: повторить один чек (в т.ч. failed — после правки данных);
 *  - --new-id: взять новый receipt_id (attempt++) — DK требует это после ответа 400.
 */
class RetryDigitalKassaReceipts extends Command
{
    protected $signature = 'fiscal:retry-digitalkassa
        {--payment= : ID платежа}
        {--new-id : Новый receipt_id (после 400 от DK)}
        {--stale-minutes=60 : Порог «зависания» для обхода без --payment}';

    protected $description = 'Повтор/дочековка чеков Digital Kassa по оплаченным платежам Точки';

    public function handle(): int
    {
        if ($this->option('payment') !== null) {
            return $this->retryOne((int) $this->option('payment'));
        }

        $stale = FiscalReceipt::query()
            ->whereIn('status', [FiscalReceipt::STATUS_PENDING, FiscalReceipt::STATUS_PROCESSING])
            ->where('updated_at', '<', now()->subMinutes(max(1, (int) $this->option('stale-minutes'))))
            ->whereHas('payment', fn ($q) => $q->whereIn('status', Payment::PAID_STATUSES))
            ->pluck('payment_id');

        foreach ($stale as $paymentId) {
            IssueDigitalKassaReceiptJob::dispatch((int) $paymentId);
        }

        $this->info("Переставлено в очередь: {$stale->count()}");

        return self::SUCCESS;
    }

    private function retryOne(int $paymentId): int
    {
        $receipt = FiscalReceipt::with('payment')->where('payment_id', $paymentId)->first();

        if (! $receipt) {
            $this->error("У платежа #{$paymentId} нет чека Digital Kassa.");

            return self::FAILURE;
        }

        if ($receipt->status === FiscalReceipt::STATUS_DONE) {
            $this->warn("Чек по платежу #{$paymentId} уже пробит (ФД {$receipt->fiscal_num}).");

            return self::SUCCESS;
        }

        if (! in_array($receipt->payment?->status, Payment::PAID_STATUSES, true)) {
            $this->error("Платёж #{$paymentId} не оплачен — чек не пробиваем.");

            return self::FAILURE;
        }

        $receipt->update([
            'status' => FiscalReceipt::STATUS_PENDING,
            'attempt' => $this->option('new-id') ? $receipt->attempt + 1 : $receipt->attempt,
        ]);

        IssueDigitalKassaReceiptJob::dispatch($paymentId);

        $this->info("Чек по платежу #{$paymentId} поставлен в очередь (receipt_id {$receipt->receiptId()}).");

        return self::SUCCESS;
    }
}
