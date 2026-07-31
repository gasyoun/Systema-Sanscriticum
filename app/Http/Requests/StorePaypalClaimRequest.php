<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Заявка студента об оплате блока/курса через PayPal (оплата из-за рубежа).
 *
 * Школа на personal/non-business PayPal — автосверки нет. Обязательные поля
 * для ручной сверки (H2017): с какого PayPal платили, дата, сумма+валюта.
 * Номер транзакции и файл чека — опциональные усилители, не замена тройки.
 * Гость дополнительно даёт имя+email (создание аккаунта).
 */
final class StorePaypalClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'foreign_amount' => ['required', 'numeric', 'min:1', 'max:1000000'],
            'foreign_currency' => ['required', 'in:USD,EUR'],
            // С какого PayPal-аккаунта ушёл перевод (email или @username).
            'paypal_payer' => ['required', 'string', 'max:255'],
            // Дата перевода по заявлению студента (календарный день, не datetime).
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'paypal_txn' => ['nullable', 'string', 'max:255'],
            'proof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ];

        // Гость → создаём аккаунт, поэтому нужны имя и email (как в депозите).
        // Залогиненный — данные берём из auth()->user().
        if (! auth()->check()) {
            $rules['name'] = ['required', 'string', 'max:255'];
            $rules['email'] = ['required', 'email', 'max:255'];
        }

        return $rules;
    }

    public function attributes(): array
    {
        return [
            'foreign_amount' => 'сумма оплаты',
            'foreign_currency' => 'валюта',
            'paypal_payer' => 'PayPal, с которого платили',
            'paid_on' => 'дата оплаты',
            'paypal_txn' => 'номер транзакции PayPal',
            'proof' => 'файл подтверждения',
            'comment' => 'комментарий',
        ];
    }

    public function messages(): array
    {
        return [
            'paypal_payer.required' => 'Укажите PayPal-аккаунт (email или @username), с которого ушёл перевод — так мы найдём платеж в личном PayPal.',
            'paid_on.required' => 'Укажите дату перевода — без неё сверка в личном PayPal занимает часы.',
            'paid_on.before_or_equal' => 'Дата оплаты не может быть в будущем.',
        ];
    }
}
