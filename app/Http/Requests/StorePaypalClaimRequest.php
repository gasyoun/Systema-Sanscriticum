<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Заявка студента об оплате блока/курса через PayPal (оплата из-за рубежа).
 *
 * Требуем сумму+валюту и хотя бы ОДНО доказательство (номер транзакции ИЛИ файл
 * чека) — админ по нему сверит платёж в PayPal. Гость дополнительно даёт имя+email
 * (на их основе создаётся аккаунт — зеркало StoreDepositRequest).
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
            // Хотя бы одно доказательство оплаты: номер транзакции ИЛИ файл-чек.
            'paypal_txn' => ['nullable', 'required_without:proof', 'string', 'max:255'],
            'proof' => ['nullable', 'required_without:paypal_txn', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
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
            'paypal_txn' => 'номер транзакции PayPal',
            'proof' => 'файл подтверждения',
            'comment' => 'комментарий',
        ];
    }

    public function messages(): array
    {
        return [
            'paypal_txn.required_without' => 'Укажите номер транзакции PayPal или приложите файл подтверждения.',
            'proof.required_without' => 'Приложите файл подтверждения или укажите номер транзакции PayPal.',
        ];
    }
}
