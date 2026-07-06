<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Payment;
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

            // Логируем только статус — без полного payload (там ФИО/суммы/атрибуты
            // покупателя, которые не должны копиться в файловых логах). Трассировка
            // по номеру заказа обеспечивается сообщениями ниже.
            Log::info('Tochka webhook получен', ['status' => $statusFromBank, 'payment_method' => $paymentMethod]);

            preg_match('/Заказ №(\d+)/', $purpose, $matches);
            $paymentId = $matches[1] ?? null;

            if (! $paymentId) {
                Log::info("Вебхук: В purpose нет номера заказа. Purpose: {$purpose}");

                return response('OK', 200);
            }

            $successStatuses = ['paid', 'authorized', 'APPROVED', 'AUTHORIZED', 'captured', 'completed'];
            $failureStatuses = ['rejected', 'canceled', 'failed'];

            // Идемпотентность: row-lock сериализует параллельные вебхуки на один и тот же платеж,
            // чтобы processSuccessfulPayment (выдача групп + welcome-email) не сработал дважды.
            DB::transaction(function () use ($paymentId, $statusFromBank, $paymentMethod, $successStatuses, $failureStatuses) {
                $payment = Payment::lockForUpdate()->find($paymentId);

                if (! $payment) {
                    Log::warning("Вебхук: Платеж с ID {$paymentId} не найден в базе!");

                    return;
                }

                if (in_array($statusFromBank, $successStatuses, true)) {
                    if ($payment->status !== 'paid') {
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
                } elseif (in_array($statusFromBank, $failureStatuses, true)) {
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
     * Нормализованный способ оплаты из payload Точки: 'sbp' | 'card' | 'dolyame' | null.
     *
     * Поле ПОДТВЕРЖДЕНО по официальной документации Точки (событие
     * `acquiringInternetPayment`): ключ — `paymentType`, значения — `"card"`,
     * `"sbp"`, `"dolyame"` (рассрочка). См.
     * https://developers.tochka.com/docs/tochka-api/opisanie-metodov/vebhuki/acquiringInternetPayment
     * `paymentType` (капабилити «какие способы предложены») — это ДРУГОЕ поле,
     * массив, поэтому его сюда не берём. Основной ключ пробуем первым; несколько
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
}
