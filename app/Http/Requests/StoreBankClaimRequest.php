<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Заявка студента об оплате банковским переводом (SEPA/SWIFT на внешний счёт
 * получателя школы за рубежом, H3497). Зеркало StorePaypalClaimRequest без
 * PayPal-специфики: обязательная тройка для ручной сверки — отправитель, дата
 * валютирования, сумма+валюта; банковская референция и файл выписки —
 * опциональные усилители. Гость дополнительно даёт имя+email.
 */
final class StoreBankClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'foreign_amount' => ['required', 'numeric', 'min:1', 'max:1000000'],
            // SEPA-счёт получателя — EUR; SWIFT-переводы могут прийти в USD/GBP.
            'foreign_currency' => ['required', 'in:EUR,USD,GBP'],
            // С чьего счёта ушёл перевод (имя отправителя / счёт) — для сверки
            // с банковской выпиской получателя.
            'sender_name' => ['required', 'string', 'max:255'],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            // Банковская референция перевода (типа FT262308KY5X).
            'reference' => ['nullable', 'string', 'max:100'],
            'proof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ];

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
            'sender_name' => 'отправитель перевода',
            'paid_on' => 'дата оплаты',
            'reference' => 'референция перевода',
            'proof' => 'файл подтверждения',
            'comment' => 'комментарий',
        ];
    }

    public function messages(): array
    {
        return [
            'sender_name.required' => 'Укажите имя/счёт отправителя — так мы найдём перевод в выписке получателя.',
            'paid_on.required' => 'Укажите дату перевода — без неё сверка по выписке занимает часы.',
            'paid_on.before_or_equal' => 'Дата оплаты не может быть в будущем.',
        ];
    }
}
