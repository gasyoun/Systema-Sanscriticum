<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreDonationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Гейт флага живёт здесь, а не только в контроллере: authorize
        // выполняется ДО валидации правил, иначе при выключенном приёме
        // гость получал бы ошибки «name/email required» вместо тихого 404.
        if (! (bool) config('institute.donations_enabled')) {
            abort(404);
        }

        return true;
    }

    public function rules(): array
    {
        // Сумма — целые рубли в механических границах конфига (не ценник:
        // пресеты и «рекомендованные суммы» ратифицирует отдельно MG).
        $rules = [
            'amount' => [
                'required',
                'integer',
                'min:'.(int) config('institute.donate_min', 100),
                'max:'.(int) config('institute.donate_max', 300000),
            ],
        ];

        // Для гостя нужны имя+email — чек Точки уходит на этот контакт,
        // а платёж привязывается к аккаунту.
        if (! auth()->check()) {
            $rules['name'] = ['required', 'string', 'max:255'];
            $rules['email'] = ['required', 'email', 'max:255'];
        }

        // Благодарность (N3): имя публикуется только при явном согласии;
        // без согласия поле имени игнорируется целиком.
        $rules['gratitude_consent'] = ['nullable', 'boolean'];
        $rules['gratitude_name'] = ['nullable', 'string', 'max:120'];

        return $rules;
    }
}
