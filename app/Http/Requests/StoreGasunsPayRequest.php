<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreGasunsPayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            // С чьего счёта ушёл перевод — для сверки с получателем.
            'sender_name' => ['required', 'string', 'max:255'],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            // Референция/номер перевода (если есть в подтверждении).
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
            'sender_name.required' => 'Укажите, с чьего счёта ушёл перевод — так мы найдём его при сверке.',
            'paid_on.required' => 'Укажите дату перевода.',
            'paid_on.before_or_equal' => 'Дата перевода не может быть в будущем.',
            'proof.max' => 'Файл подтверждения — до 5 МБ (jpg, png или pdf).',
        ];
    }
}
