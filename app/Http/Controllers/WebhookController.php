<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Payment;
use Illuminate\Support\Facades\Log;
use Firebase\JWT\JWT;
use Firebase\JWT\JWK;
use Firebase\JWT\Key;

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
            $jwk = json_decode(self::TOCHKA_PUBLIC_KEY, true, 512, JSON_THROW_ON_ERROR);
            $key = JWK::parseKey($jwk, 'RS256');

            try {
                $decoded = JWT::decode($jwt, $key);
            } catch (\UnexpectedValueException $e) {
                Log::warning('Tochka webhook: невалидная подпись JWT', [
                    'ip'    => $request->ip(),
                    'error' => $e->getMessage(),
                ]);
                return response('Invalid signature', 401);
            }

            $payload = (array) $decoded;
            Log::info('--- ДАННЫЕ ВЕБХУКА ---', $payload);

            // ========== БИЗНЕС-ЛОГИКА ==========
            $purpose = $payload['purpose'] ?? '';
            $statusFromBank = $payload['status'] ?? null;

            preg_match('/Заказ №(\d+)/', $purpose, $matches);
            $paymentId = $matches[1] ?? null;

            if (! $paymentId) {
                Log::info("Вебхук: В purpose нет номера заказа. Purpose: {$purpose}");
                return response('OK', 200);
            }

            $payment = Payment::find($paymentId);

            if (! $payment) {
                Log::warning("Вебхук: Платеж с ID {$paymentId} не найден в базе!");
                return response('OK', 200);
            }

            $successStatuses = ['paid', 'authorized', 'APPROVED', 'AUTHORIZED', 'captured', 'completed'];

            if (in_array($statusFromBank, $successStatuses, true)) {
                if ($payment->status !== 'paid') {
                    $payment->update(['status' => 'paid']);
                    Log::info("✅ УСПЕХ: Доступ выдан! Заказ №{$payment->id} оплачен.");
                }
            } elseif (in_array($statusFromBank, ['rejected', 'canceled', 'failed'], true)) {
                $payment->update(['status' => 'failed']);
                Log::info("❌ ОТКАЗ: Заказ №{$payment->id} отменен банком.");
            }

            return response('OK', 200);

        } catch (\Exception $e) {
            Log::error('Ошибка Вебхука: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return response('Server error', 500);
        }
    }
}