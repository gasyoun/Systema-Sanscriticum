<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\FiscalReceipt;
use App\Services\Fiscal\DigitalKassaClient;
use App\Services\Fiscal\DigitalKassaReceiptBuilder;
use App\Support\MoneySli\MoneySliAlerter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Пробивает чек прихода в Digital Kassa по оплаченному платежу Точки
 * (features.digitalkassa_receipts, fiscal_provider=digitalkassa).
 *
 * Доступ от чека НЕ зависит: джоба ставится после commit перехода в paid и
 * ничего в платеже не меняет. Сбой → ретраи, затем failed + алерт в денежный TG.
 *
 *  - 200/201            → done (fiscal_num/sign, receipt_url);
 *  - 202                → processing, release; следующий прогон спрашивает GET-статус;
 *  - 5xx / сеть         → исключение → ретрай с ТЕМ ЖЕ receipt_id (DK идемпотентен);
 *  - 400/401/402/403/…  → failed + алерт; после правки данных — fiscal:retry-digitalkassa.
 */
class IssueDigitalKassaReceiptJob implements ShouldQueue, ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 12;

    /** 10с … 1ч: DK просит перепроверять 202 «через несколько секунд», 5xx — чаще временные. */
    public function backoff(): array
    {
        return [10, 30, 60, 120, 300, 600, 1800, 3600];
    }

    public function __construct(public readonly int $paymentId) {}

    public function handle(DigitalKassaClient $client, DigitalKassaReceiptBuilder $builder, MoneySliAlerter $alerter): void
    {
        $receipt = FiscalReceipt::with('payment.user')->where('payment_id', $this->paymentId)->first();

        if (! $receipt || in_array($receipt->status, [FiscalReceipt::STATUS_DONE, FiscalReceipt::STATUS_FAILED], true)) {
            return;
        }

        if (! DigitalKassaClient::configured()) {
            $this->markFailed($receipt, $alerter, 'Digital Kassa не настроена (DIGITALKASSA_ACTOR_ID/TOKEN/C_GROUP_ID).');

            return;
        }

        if ($receipt->status === FiscalReceipt::STATUS_PROCESSING) {
            $this->handleResponse($receipt, $client->receiptStatus($receipt->receiptId()), $alerter);

            return;
        }

        try {
            $body = $builder->build($receipt->payment, $receipt);
        } catch (InvalidArgumentException $e) {
            $this->markFailed($receipt, $alerter, $e->getMessage());

            return;
        }

        $receipt->update(['request_json' => $body]);

        $this->handleResponse($receipt, $client->createReceipt($receipt->receiptId(), $body), $alerter);
    }

    /** Ретраи исчерпаны (5xx/сеть/затянувшийся 202). */
    public function failed(Throwable $e): void
    {
        $receipt = FiscalReceipt::where('payment_id', $this->paymentId)->first();
        if ($receipt && $receipt->status !== FiscalReceipt::STATUS_DONE) {
            $this->markFailed($receipt, app(MoneySliAlerter::class), 'Ретраи исчерпаны: '.$e->getMessage());
        }
    }

    private function handleResponse(FiscalReceipt $receipt, Response $response, MoneySliAlerter $alerter): void
    {
        $status = $response->status();

        if ($status === 200 || $status === 201) {
            $receipt->update([
                'status' => FiscalReceipt::STATUS_DONE,
                'fiscal_num' => $response->json('doc.fiscal_num'),
                'fiscal_sign' => $response->json('doc.fiscal_sign'),
                'receipt_url' => $response->json('service.receipt_url'),
                'registered_at' => $response->json('doc.reg_time'),
                'last_error' => null,
            ]);
            Log::info("🧾 Чек DK пробит: заказ №{$receipt->payment_id}, ФД {$receipt->fiscal_num}.");

            return;
        }

        if ($status === 202) {
            $receipt->update(['status' => FiscalReceipt::STATUS_PROCESSING]);
            $backoff = $this->backoff();
            $this->release($backoff[min(max($this->attempts() - 1, 0), count($backoff) - 1)]);

            return;
        }

        if ($status >= 500) {
            $receipt->update(['last_error' => "HTTP {$status}: ".mb_substr($response->body(), 0, 1000)]);

            throw new RuntimeException("Digital Kassa HTTP {$status} по заказу №{$receipt->payment_id}");
        }

        $this->markFailed($receipt, $alerter, "HTTP {$status}: ".mb_substr($response->body(), 0, 1000));
    }

    private function markFailed(FiscalReceipt $receipt, MoneySliAlerter $alerter, string $error): void
    {
        $receipt->update(['status' => FiscalReceipt::STATUS_FAILED, 'last_error' => $error]);

        Log::error("Чек DK не пробит: заказ №{$receipt->payment_id}", ['error' => $error]);

        $alerter->alert(
            'digitalkassa_receipt',
            'Чек Digital Kassa не пробит',
            [
                "Заказ №{$receipt->payment_id}, сумма {$receipt->payment?->amount} ₽ (доступ выдан)",
                mb_substr($error, 0, 300),
                "Повтор: php artisan fiscal:retry-digitalkassa --payment={$receipt->payment_id}",
            ],
            false,
            false,
        );
    }
}
