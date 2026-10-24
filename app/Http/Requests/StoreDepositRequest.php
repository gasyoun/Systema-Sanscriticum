<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Rules\HouseEmail;
use App\Support\Consent\ConsentRules;
use Illuminate\Foundation\Http\FormRequest;

final class StoreDepositRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Для гостя нужны имя+email — на их основе создаём аккаунт и
        // отправляем welcome-письмо при `paid`-вебхуке.
        // Для залогиненного — никаких полей, данные берём из auth()->user().
        if (auth()->check()) {
            return [];
        }

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'max:255', new HouseEmail],
            // 152-ФЗ: путь оплаты брони — обязательность по флагу money-контура.
            'pd_consent' => ConsentRules::pd(checkout: true),
        ];
    }

    public function messages(): array
    {
        return ConsentRules::messages();
    }
}
