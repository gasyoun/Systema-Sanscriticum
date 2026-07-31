<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Заявка на счёт для компании / ИП (безнал по реквизитам).
 *
 * Создаёт pending Payment provider=invoice; доступ только после ручной
 * сверки поступления в Filament (H2017).
 */
final class StoreCompanyInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'company_name' => ['required', 'string', 'max:255'],
            // 10 digits (юрлицо) or 12 (ИП) — loose numeric string, no checksum.
            'inn' => ['required', 'string', 'regex:/^\d{10}(\d{2})?$/'],
            'kpp' => ['nullable', 'string', 'regex:/^\d{9}$/'],
            'legal_address' => ['required', 'string', 'max:500'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
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
            'company_name' => 'название организации',
            'inn' => 'ИНН',
            'kpp' => 'КПП',
            'legal_address' => 'юридический адрес',
            'contact_phone' => 'телефон',
            'comment' => 'комментарий',
        ];
    }

    public function messages(): array
    {
        return [
            'inn.regex' => 'ИНН — 10 цифр (организация) или 12 (ИП).',
            'kpp.regex' => 'КПП — 9 цифр (для организаций).',
        ];
    }
}
