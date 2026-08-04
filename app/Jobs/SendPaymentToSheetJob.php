<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendPaymentToSheetJob implements ShouldQueue, ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Количество попыток при падении HTTP */
    public int $tries = 5;

    /** Экспоненциальный backoff между попытками: 10с, 30с, 1мин, 5мин, 10мин */
    public function backoff(): array
    {
        return [10, 30, 60, 300, 600];
    }

    /**
     * Передаём id, а не модель — чтобы SerializesModels подтянул свежую версию из БД
     * на момент выполнения (на случай, если запись правили несколько раз подряд).
     */
    public function __construct(
        public readonly int $paymentId,
        public readonly string $action // 'create' | 'update' — для логов в n8n
    ) {}

    public function handle(): void
    {
        /** @var Payment|null $payment */
        $payment = Payment::with(['user', 'course'])->find($this->paymentId);

        if (! $payment) {
            // Удалили, пока задача висела в очереди — молча выходим
            return;
        }

        // Повторная защита на уровне job (вдруг статус/признак откатили назад,
        // пока задача висела в очереди). Зеркалит PaymentObserver::isSyncable():
        // только paid/success и не «обещанный» доступ. Сумму не проверяем —
        // нулевые оплаты и отрицательные расходы тоже должны попасть в таблицу.
        if (! in_array($payment->status, Payment::PAID_STATUSES, true) || $payment->is_conditional) {
            return;
        }

        $webhookUrl = config('services.n8n.payments_webhook');

        if (empty($webhookUrl)) {
            Log::warning('n8n payments webhook URL не задан в config/services.php');

            return;
        }

        // H1960: n8n Webhook Header Auth (X-Webhook-Secret). Same pattern as
        // clip_extract / monthly_schedule / social_post. Empty secret → no header
        // (local/dev without n8n auth); prod must set N8N_PAYMENTS_WEBHOOK_SECRET
        // once the n8n node has Header Auth enabled.
        $headers = [];
        $secret = config('services.n8n.payments_webhook_secret');
        if (! empty($secret)) {
            $headers['X-Webhook-Secret'] = (string) $secret;
        }

        $response = Http::withHeaders($headers)
            ->timeout(10)
            ->acceptJson()
            ->asJson()
            ->post($webhookUrl, $this->buildPayload($payment));

        // Если n8n ответил 4xx/5xx — кинем исключение, Laravel отправит job на ретрай
        $response->throw();
    }

    /**
     * Формируем payload под структуру гугл-таблицы.
     * Ключи здесь = имена колонок в n8n при маппинге в Google Sheets.
     */
    private function buildPayload(Payment $payment): array
    {
        return [
            'action' => $this->action,
            'id' => $payment->id,
            'student' => $payment->user?->name ?? 'Удалён',
            'student_email' => $payment->user?->email,
            'course' => $payment->course?->title ?? 'Курс удалён',
            'start_block' => $payment->start_block,
            'end_block' => $payment->end_block,
            'amount' => (float) $payment->amount,
            // Скидка одной колонкой: «-10%» для процентной, «-1000 ₽» для
            // фиксированной, пусто — без скидки. Замапь в одну колонку в n8n.
            'discount' => $payment->discountLabel(),
            'tariff' => $payment->tariff,
            // Справочная сумма в валюте (USD/EUR) — «50 $» / «45 €». Замапь в
            // колонку «Примечание». В рублёвых расчётах не участвует.
            'note' => $payment->foreignAmountLabel(),
            // Человекочитаемая пометка операции (бронь / пробное / блок / курс /
            // расход) — та же, что в админке. Замапь в отдельную колонку в n8n.
            'label' => $payment->operationLabel(),
            'status' => $payment->status,
            'transaction_id' => $payment->transaction_id,
            'paid_at' => $payment->updated_at?->format('d.m.Y'),
            'created_at' => $payment->created_at?->format('d.m.Y H:i'),
        ];
    }

    /**
     * Финальный fail после всех ретраев — уведомляем в лог.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('SendPaymentToSheetJob провалился окончательно', [
            'payment_id' => $this->paymentId,
            'action' => $this->action,
            'error' => $exception->getMessage(),
        ]);
    }
}
