<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Payment;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function handleTochkaWebhook(Request $request)
    {
        try {
            $jwt = $request->getContent();
            $parts = explode('.', $jwt);
            if (count($parts) !== 3) return response('OK', 200);

            $payload = json_decode(base64_decode($parts[1]), true);
            Log::info('--- ДАННЫЕ ВЕБХУКА ---', $payload);

            // 1. ДОСТАЕМ ТЕКСТ НАЗНАЧЕНИЯ ПЛАТЕЖА И СТАТУС
            $purpose = $payload['purpose'] ?? '';
            $statusFromBank = $payload['status'] ?? null;

            // 2. ВЫТАСКИВАЕМ ID ПЛАТЕЖА ИЗ ТЕКСТА (ищем "Заказ №123")
            preg_match('/Заказ №(\d+)/', $purpose, $matches);
            $paymentId = $matches[1] ?? null;

            // Если это тестовый запрос банка или мы не нашли номер — просто говорим ОК и уходим
            if (!$paymentId) {
                Log::info("Вебхук: В purpose нет номера заказа. Пропускаем. Purpose: {$purpose}");
                return response('OK', 200);
            }

            // 3. ИЩЕМ ПЛАТЕЖ В БАЗЕ ПО ЕГО РОДНОМУ ID
            $payment = Payment::find($paymentId);

            if (!$payment) {
                Log::warning("Вебхук: Платеж с ID {$paymentId} не найден в базе!");
                return response('OK', 200);
            }

            // 4. МЕНЯЕМ СТАТУС
            $successStatuses = ['paid', 'authorized', 'APPROVED', 'captured', 'completed'];
            
            if (in_array($statusFromBank, $successStatuses)) {
                if ($payment->status !== 'paid') {
                    $payment->update(['status' => 'paid']);
                    Log::info("✅ УСПЕХ: Доступ выдан! Заказ №{$payment->id} оплачен.");
                }
            } elseif (in_array($statusFromBank, ['rejected', 'canceled', 'failed'])) {
                $payment->update(['status' => 'failed']);
                Log::info("❌ ОТКАЗ: Заказ №{$payment->id} отменен банком.");
            }

            return response('OK', 200);

        } catch (\Exception $e) {
            Log::error('Ошибка Вебхука: ' . $e->getMessage());
            return response('OK', 200);
        }
    }
}