<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Models\FiscalReceipt;
use App\Models\Payment;
use App\Support\PhoneE164;
use InvalidArgumentException;

/**
 * Payment + FiscalReceipt → тело «кассового чека» DK (приход, одна позиция-услуга).
 * Паритет с прежним чеком Точки: quantity=1, amount позиции = итог, НДС/СНО из конфига,
 * признак способа расчёта сохранён на FiscalReceipt при создании ссылки.
 */
final class DigitalKassaReceiptBuilder
{
    /** Тег 1054: приход. */
    private const TYPE_INCOME = 1;

    /** Тег 1212: услуга. */
    private const ITEM_TYPE_SERVICE = 4;

    /** Тег 2108: штуки/единицы. */
    private const UNIT_PIECE = 0;

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException нет ни email, ни телефона E.164 — DK отклонит чек.
     */
    public function build(Payment $payment, FiscalReceipt $receipt): array
    {
        $amount = round((float) $payment->amount, 2);

        return [
            'type' => self::TYPE_INCOME,
            'items' => [[
                'type' => self::ITEM_TYPE_SERVICE,
                'name' => mb_substr($receipt->item_name, 0, 128),
                'price' => $amount,
                'quantity' => 1,
                'amount' => $amount,
                'payment_method' => $receipt->payment_method,
                'unit' => self::UNIT_PIECE,
                'vat' => (int) config('services.digitalkassa.vat'),
            ]],
            'taxation' => (int) config('services.digitalkassa.taxation'),
            'is_internet' => 1,
            'timezone' => (int) config('services.digitalkassa.timezone'),
            'amount' => ['cashless' => $amount],
            'notify' => $this->notify($payment),
            'loc' => ['billing_place' => (string) config('services.digitalkassa.billing_place')],
        ];
    }

    /** @return array<string, mixed> */
    private function notify(Payment $payment): array
    {
        $email = trim((string) $payment->user?->email);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['emails' => [$email]];
        }

        $phone = PhoneE164::normalize($payment->user?->phone);
        if ($phone !== null) {
            return ['phone' => $phone];
        }

        throw new InvalidArgumentException("Платёж #{$payment->id}: нет email и телефона E.164 для отправки чека.");
    }
}
