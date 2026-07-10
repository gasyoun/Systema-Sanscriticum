<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\AttributionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreTrialRequest extends FormRequest
{
    // Ошибки валидации уходят в именованный bag, чтобы модалка пробного на витрине
    // могла отличить их от прочих форм страницы и открыться заново с текстом ошибки.
    protected $errorBag = 'trial';

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
            'signup_source' => ['nullable', 'string', Rule::in(AttributionService::SIGNUP_SOURCES)],
        ];
    }
}
