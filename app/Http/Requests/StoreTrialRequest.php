<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreTrialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Гость: те же поля, что на чекауте (фамилия, имя, email, город) — создаём
        // аккаунт и шлём пароль на paid-вебхуке. Залогиненный — данные из auth()->user().
        if (auth()->check()) {
            return [];
        }

        return [
            'surname' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
        ];
    }
}
