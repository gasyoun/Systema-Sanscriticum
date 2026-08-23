<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\InstituteDonation;
use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    /**
     * Публичный ключ Точки (RSA, RS256).
     * Источник: https://enter.tochka.com/doc/openapi/static/keys/public
     */
    private const TOCHKA_PUBLIC_KEY = '{"kty":"RSA","e":"AQAB","n":"rwm77av7GIttq-JF1itEgLCGEZW_zz16RlUQVYlLbJtyRSu61fCec_rroP6PxjXU2uLzUOaGaLgAPeUZAJrGuVp9nryKgbZceHckdHDYgJd9TsdJ1MYUsXaOb9joN9vmsCscBx1lwSlFQyNQsHUsrjuDk-opf6RCuazRQ9gkoDCX70HV8WBMFoVm-YWQKJHZEaIQxg_DU4gMFyKRkDGKsYKA0POL-UgWA1qkg6nHY5BOMKaqxbc5ky87muWB5nNk4mfmsckyFv9j1gBiXLKekA_y4UwG2o1pbOLpJS3bP_c95rm4M9ZBmGXqfOQhbjz8z-s9C11i-jmOQ2ByohS-ST3E5sqBzIsxxrxyQDTw--bZNhzpbciyYW4GfkkqyeYoOPd_84jPTBDKQXssvj8ZOj2XboS77tvEO1n1WlwUzh8HPCJod5_fEgSXuozpJtOggXBv0C2ps7yXlDZf-7Jar0UYc_NJEHJF-xShlqd6Q3sVL02PhSCM-ibn9DN9BKmD"}';

    public function handleTochkaWebhook(Request $request)
    {
        try {
            $jwt = $request->getContent();

            // ========== ВЕРИФИКАЦИЯ ПОДПИСИ RS256 ==========
            // Ключ можно переопределить через config (services.tochka.webhook_public_key)
            // — нужно для тестов; в проде fallback на зашитый публичный ключ Точки.
            $publicKey = config('services.tochka.webhook_public_key') ?: self::TOCHKA_PUBLIC_KEY;
            $jwk = json_decode($publicKey, true, 512, JSON_THROW_ON_ERROR);
            $key = JWK::parseKey($jwk, 'RS256');

            try {
                $decoded = JWT::decode($jwt, $key);
            } catch (\UnexpectedValueException $e) {
                Log::warning('Tochka webhook: невалидная подпись JWT', [
                    'ip' => $request->ip(),
                    'error' => $e->getMessage(),
                ]);

                return response('Invalid signature', 401);
            }

            $payload = (array) $decoded;

            // ========== БИЗНЕС-ЛОГИКА ==========
            $purpose = $payload['purpose'] ?? '';
            $statusFromBank = $payload['status'] ?? null;

            // Способ оплаты (СБП/карта) — для точного расчёта комиссии эквайринга в
            // «Юнит-экономике». Точное имя поля в payload Точки пока не подтверждено
            // на реальном платеже (см. extractPaymentMethod): пробуем известные
            // варианты, а нераспознанное логируем, чтобы подтвердить ключ и потом
            // сузить эвристику. NULL = считаем такой платёж вилкой.
            $paymentMethod = $this->extractPaymentMethod($payload);

            // Сумма из банка — для сверки с payments.amount (H1359, guard c).
            // Извлекается так же оборонительно, как способ оплаты: отсутствует => null => не сверяем.
            $reportedAmount = $this->extractReportedAmount($payload);

            // Институтские пожертвования (N2): purpose «…№D{id}» намеренно не
            // матчится в «Заказ №» — успех меняет только строку institute_donations,
            // платёжный success-путь с доступами не трогается.
            if (preg_match('/№\s*D\s*(\d+)/u', $purpose, $donationMatches)) {
                $this->handleDonationWebhook(
                    (int) $donationMatches[1],
                    is_string($statusFromBank) ? mb_strtolower(trim($statusFromBank)) : null,
                    $reportedAmount,
                );

                return response('OK', 200);
            }

            // Логируем только статус — без полного payload (там ФИО/суммы/атрибуты
            // покупателя, которые не должны копиться в файловых логах). Трассировка
            // по номеру заказа обеспечивается сообщениями ниже.
            Log::info('Tochka webhook получен', ['status' => $statusFromBank, 'payment_method' => $paymentMethod]);

            preg_match('/Заказ №(\d+)/', $purpose, $matches);
            $paymentId = $matches[1] ?? null;

            if (! $paymentId) {
                // H2085: was info — charged bank events with wrong purpose stayed quiet.
                // Soft 200 stays intentional (Tochka retries on non-2xx for forever-noise
                // on non-order purposes); LOUD log is the operator signal.
                Log::warning('Вебхук: в purpose нет «Заказ №{id}» — доступ не будет выдан', [
                    'purpose' => $purpose,
                    'status' => $statusFromBank,
                ]);
            }

            // Идемпотентность по телу события (H1359): sha256 сырого JWT.
            $eventHash = hash('sha256', $jwt);
            $guard = (bool) config('features.tochka_webhook_guard');

            // (a) Повтор уже виденной доставки: при включённой защите — 200-no-op,
            // не переигрываем success-путь. Уникальный индекс event_hash — истинный
            // страж от гонок; эта проверка лишь даёт ответить 200 без второй вставки.
            if ($guard && PaymentWebhookEvent::where('event_hash', $eventHash)->exists()) {
                Log::info("Вебхук: повтор доставки (event_hash) — no-op. Заказ №{$paymentId}");

                return response('OK', 200);
            }

            // Settled money may open access. Tochka acquiringInternetPayment docs:
            //   APPROVED  — money taken (card one-shot, SBP always, Dolyame always)
            //   AUTHORIZED — hold only (two-stage card); wait for later APPROVED
            // `captured`/`completed`/`paid` kept as aliases for older/other payloads.
            // Regression (PR #1103): success was reduced to captured/completed only →
            // real APPROVED webhooks left payments pending while the bank had charged.
            $normalizedBankStatus = is_string($statusFromBank)
                ? mb_strtolower(trim($statusFromBank))
                : null;
            $successStatuses = ['approved', 'captured', 'completed', 'paid'];
            $holdStatuses = ['authorized'];
            $failureStatuses = ['rejected', 'canceled', 'cancelled', 'failed'];

            // Идемпотентность: row-lock сериализует параллельные вебхуки на один и тот же платеж,
            // чтобы processSuccessfulPayment (выдача групп + welcome-email) не сработал дважды.
            DB::transaction(function () use ($paymentId, $statusFromBank, $normalizedBankStatus, $paymentMethod, $reportedAmount, $eventHash, $guard, $successStatuses, $failureStatuses, $holdStatuses) {
                $payment = $paymentId ? Payment::lockForUpdate()->find($paymentId) : null;

                // Решение по этой доставке для журнала; по умолчанию — как раньше.
                $decision = PaymentWebhookEvent::DECISION_APPLIED;
                $apply = true;

                if (! $payment) {
                    // Подписанная доставка без совпавшего локального платежа.
                    $decision = PaymentWebhookEvent::DECISION_UNMATCHED;
                    $apply = false;
                    if ($paymentId) {
                        Log::warning("Вебхук: Платеж с ID {$paymentId} не найден в базе!");
                    }
                } elseif (in_array($normalizedBankStatus, $holdStatuses, true)) {
                    // Bank hold ≠ capture — do not grant access.
                    $decision = PaymentWebhookEvent::DECISION_HOLD_NOT_CAPTURED;
                    $apply = false;
                    Log::warning("⛔ Вебхук: hold (status={$statusFromBank}) для заказа №{$payment->id} — доступ НЕ выдан (ждём APPROVED/captured).", [
                        'payment_id' => $payment->id,
                        'bank_status' => $statusFromBank,
                    ]);
                } elseif (in_array($normalizedBankStatus, $successStatuses, true)) {
                    // (b) Воскрешение: платёж был оплачен и затем отменён/возвращён.
                    // Повторный/переигранный success-JWT не должен воскрешать доступ,
                    // депозит, промо-слот и реферала (см. fireOnPaid blast radius в PR).
                    if ($guard
                        && ! in_array($payment->status, Payment::PAID_STATUSES, true)
                        && $payment->hasPriorPaidTransition()
                    ) {
                        $decision = PaymentWebhookEvent::DECISION_REJECTED_RESURRECTION;
                        $apply = false;
                        Log::warning("⛔ Вебхук: отклонено воскрешение отменённого платежа №{$payment->id} (текущий статус {$payment->status}).");
                    }
                    // (c) Сумма из банка расходится с суммой заказа сверх допуска.
                    elseif ($guard && $this->amountMismatches($payment, $reportedAmount)) {
                        $decision = PaymentWebhookEvent::DECISION_REJECTED_AMOUNT_MISMATCH;
                        $apply = false;
                        Log::warning("⛔ Вебхук: сумма банка {$reportedAmount} расходится с заказом №{$payment->id} ({$payment->amount}).");
                    }
                }

                // Журнал: одна строка на КАЖДУЮ подписанную доставку (аддитивно, не
                // зависит от флага). firstOrCreate — чтобы повтор при флаге OFF не
                // ломал уникальный индекс, а сохранял идемпотентный no-op как раньше.
                PaymentWebhookEvent::firstOrCreate(
                    ['event_hash' => $eventHash],
                    [
                        'provider' => 'tochka',
                        'payment_id' => $payment?->id,
                        'bank_status' => $statusFromBank,
                        'reported_amount' => $reportedAmount,
                        'decision' => $decision,
                        'created_at' => now(),
                    ]
                );

                if (! $apply || ! $payment) {
                    return;
                }

                if (in_array($normalizedBankStatus, $successStatuses, true)) {
                    if ($payment->status !== 'paid') {
                        $requiresCourseGroups = $payment->course_id !== null
                            && ! $payment->isDeposit()
                            && ! $payment->isTrial()
                            && ! $payment->isMarathonPaid()
                            && ! $payment->isExpense()
                            && ! $payment->isSalaryPayout();

                        if ($requiresCourseGroups && ! $payment->course?->groups()->exists()) {
                            throw new \RuntimeException(
                                "Вебхук оплаты #{$payment->id}: у курса нет группы доступа; повторите доставку после настройки."
                            );
                        }

                        $update = ['status' => 'paid'];
                        // Способ оплаты пишем только когда распознан — не затираем
                        // уже сохранённый NULL'ом на возможных повторных вебхуках.
                        if ($paymentMethod !== null) {
                            $update['payment_method'] = $paymentMethod;
                        }
                        $payment->update($update);
                        Log::info("✅ УСПЕХ: Доступ выдан! Заказ №{$payment->id} оплачен.");
                    } elseif ($paymentMethod !== null && $payment->payment_method === null) {
                        // Платёж уже был отмечен оплаченным, но без способа — дозаполняем.
                        $payment->update(['payment_method' => $paymentMethod]);
                    }
                } elseif (in_array($normalizedBankStatus, $failureStatuses, true)) {
                    if ($payment->status !== 'failed') {
                        $payment->update(['status' => 'failed']);
                        Log::info("❌ ОТКАЗ: Заказ №{$payment->id} отменен банком.");
                    }
                }
            });

            return response('OK', 200);

        } catch (\Exception $e) {
            Log::error('Ошибка Вебхука: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response('Server error', 500);
        }
    }

    /**
     * Сумма платежа из payload Точки, в рублях, или null если поля нет.
     *
     * Извлекается оборонительно, как и способ оплаты (extractPaymentMethod):
     * точное имя поля в реальном payload Точки ещё не подтверждено, поэтому
     * пробуем известные варианты; отсутствие поля => null => сверка суммы
     * пропускается (доступ выдаётся как раньше). Так гуард остаётся свободен от
     * любой зависимости от живого банка/кредов.
     *
     * @param  array<string, mixed>  $payload
     */
    private function extractReportedAmount(array $payload): ?float
    {
        foreach (['amount', 'sum', 'paymentAmount', 'totalAmount', 'operationAmount'] as $k) {
            if (isset($payload[$k]) && is_numeric($payload[$k])) {
                return (float) $payload[$k];
            }
        }

        return null;
    }

    /**
     * Сумма из банка расходится с payments.amount сверх допуска
     * (config('checkout.webhook_amount_tolerance'))? Сумма не пришла => не сверяем.
     */
    private function amountMismatches(Payment $payment, ?float $reportedAmount): bool
    {
        if ($reportedAmount === null) {
            return false;
        }

        $tolerance = (float) config('checkout.webhook_amount_tolerance', 1.00);

        return abs($reportedAmount - (float) $payment->amount) > $tolerance;
    }

    /**
     * Нормализованный способ оплаты из payload Точки: 'sbp' | 'card' | 'dolyame' | null.
     *
     * Поле ПОДТВЕРЖДЕНО по официальной документации Точки (событие
     * `acquiringInternetPayment`): ключ — `paymentType`, значения — `"card"`,
     * `"sbp"`, `"dolyame"` (рассрочка). См.
     * https://developers.tochka.com/docs/tochka-api/opisanie-metodov/vebhuki/acquiringInternetPayment
     * `paymentMode` (капабилити «какие способы предложены при создании ссылки») —
     * это ДРУГОЕ поле, массив, поэтому его сюда не берём. Основной ключ пробуем первым; несколько
     * форматных фолбэков оставлены на случай смены регистра/нотации. Нераспознанное
     * значение (например новый способ) логируем без PII и возвращаем null — такой
     * платёж «Юнит-экономика» посчитает вилкой.
     *
     * @param  array<string, mixed>  $payload
     */
    private function extractPaymentMethod(array $payload): ?string
    {
        $raw = null;
        foreach (['paymentType', 'payment_type', 'type'] as $k) {
            if (! empty($payload[$k]) && is_string($payload[$k])) {
                $raw = $payload[$k];
                break;
            }
        }

        if ($raw === null) {
            return null;
        }

        $v = mb_strtolower(trim($raw));

        if (str_contains($v, 'sbp') || str_contains($v, 'qr') || str_contains($v, 'fps')) {
            return 'sbp';
        }
        if (str_contains($v, 'card') || str_contains($v, 'карт')) {
            return 'card';
        }
        if (str_contains($v, 'dolyame') || str_contains($v, 'доляме')) {
            return 'dolyame';
        }

        // Способ пришёл, но не распознан — зафиксировать сырое значение для настройки.
        Log::info('Tochka webhook: нераспознанный способ оплаты', ['raw' => $v]);

        return null;
    }

    /**
     * N2: завершение пожертвования Института. Идемпотентно по статусу строки:
     * повторный success-вебхук на уже оплаченный донат — no-op. Сумма банка
     * сверяется с заказанной; hold (AUTHORIZED) игнорируется до capture.
     */
    private function handleDonationWebhook(int $donationId, ?string $normalizedBankStatus, ?float $reportedAmount): void
    {
        $success = ['approved', 'captured', 'completed', 'paid'];
        $failure = ['rejected', 'canceled', 'cancelled', 'failed'];

        $donation = InstituteDonation::find($donationId);

        if (! $donation) {
            Log::warning("Вебхук: пожертвование D{$donationId} не найдено в базе");

            return;
        }

        // Аудит: последнее сырое состояние от банка (без payload с ФИО).
        $donation->forceFill(['last_bank_status' => mb_substr((string) $normalizedBankStatus, 0, 64)])->save();

        if (in_array($normalizedBankStatus, $success, true)) {
            if ($donation->status === InstituteDonation::STATUS_PAID) {
                return; // идемпотентность: успех уже применён
            }

            if ($reportedAmount !== null
                && abs($reportedAmount - (float) $donation->amount) > 0.01) {
                Log::warning("Вебхук: сумма банка {$reportedAmount} расходится с пожертвованием D{$donation->id} ({$donation->amount})");

                return;
            }

            $donation->update([
                'status' => InstituteDonation::STATUS_PAID,
                'paid_at' => now(),
            ]);
            Log::info("Пожертвование D{$donation->id} оплачено");

            return;
        }

        if (in_array($normalizedBankStatus, $failure, true)
            && $donation->status === InstituteDonation::STATUS_PENDING) {
            $donation->update(['status' => InstituteDonation::STATUS_FAILED]);
        }
    }
}
