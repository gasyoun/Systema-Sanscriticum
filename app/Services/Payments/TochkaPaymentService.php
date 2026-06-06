<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Создаёт платёжную ссылку Точки с фискализацией — метод Create Payment Operation
 * With Receipt (POST /acquiring/v1.0/payments_with_receipt).
 *
 * В отличие от нефискального /payments, этот метод заставляет кассу пробить чек и
 * отправить его на email из Client — то есть покупателю (студенту), а не на дефолтную
 * почту руководителя (как было раньше — отсюда «чужой покупатель» в чеке).
 * См. план kind-tinkering-stardust + ответ поддержки Точки.
 */
final class TochkaPaymentService
{
    /**
     * @param  string  $itemName  Название позиции чека (курс/тариф).
     * @param  string  $paymentMethod  Признак способа расчёта (enum Точки): full_payment (полная оплата) | full_prepayment (предоплата/бронь).
     * @param  string  $paymentObject  Признак предмета расчёта (enum Точки): goods | service | work. Для курсов/брони — service.
     *
     * @throws \Illuminate\Http\Client\ConnectionException Сетевой сбой — обрабатывает вызывающий код.
     */
    public function createPaymentWithReceipt(
        User $user,
        float $amount,
        string $purpose,
        string $itemName,
        string $paymentMethod = 'full_payment',
        string $paymentObject = 'service',
    ): Response {
        $amount = round($amount, 2);

        $data = [
            'customerCode' => config('services.tochka.customer_code'),
            'amount' => $amount,
            'purpose' => $purpose,
            'paymentMode' => ['sbp', 'card'],
            'redirectUrl' => route('payment.success'),
            'failRedirectUrl' => route('payment.fail'),
            // Контакт покупателя — чтобы чек ушёл студенту, а не на дефолтную почту руководителя.
            'Client' => array_filter([
                'email' => $user->email,
                'phone' => $user->phone,
                'name' => $user->name,
            ]),
            'Items' => [[
                'name' => mb_substr($itemName, 0, 128),
                // Сумма позиций обязана совпадать с amount, иначе Точка отклонит чек.
                // quantity=1 → amount позиции = итогу, без неоднозначности «цена за ед. × кол-во».
                'amount' => $amount,
                'quantity' => 1,
                'vatType' => config('services.tochka.vat_type', 'none'),
                'paymentMethod' => $paymentMethod,
                'paymentObject' => $paymentObject,
            ]],
        ];

        // merchantId — обязателен, если у ИП > 1 торговой точки в Точке: без него банк
        // сам выбирает retailer'а, а от него зависит «адрес сайта» в фискальном чеке.
        // Пусто → не передаём поле (одна merchant'а у ИП — старое поведение).
        $merchantId = config('services.tochka.merchant_id');
        if (! empty($merchantId)) {
            $data['merchantId'] = $merchantId;
        }

        // СНО включаем только если задана в конфиге — иначе полагаемся на дефолт кассы.
        $taxSystemCode = config('services.tochka.tax_system_code');
        if (! empty($taxSystemCode)) {
            $data['taxSystemCode'] = $taxSystemCode;
        }

        return Http::withToken(config('services.tochka.token'))
            ->withHeaders(['Accept' => 'application/json'])
            ->connectTimeout(5)
            ->timeout(20)
            ->post(rtrim((string) config('services.tochka.url'), '/').'/payments_with_receipt', [
                'Data' => $data,
            ]);
    }
}
