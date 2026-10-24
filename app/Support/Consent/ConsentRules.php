<?php

declare(strict_types=1);

namespace App\Support\Consent;

/**
 * Правило валидации галочки согласия на обработку ПДн (pd_consent).
 *
 * Обязательность на сервере — за флагом (features.pd_consent_enforce, а для
 * оплаты — features.checkout_pd_consent_enforce): пока флаг выключен, поле
 * принимается как необязательное, но всё равно пишется в журнал consents.
 */
final class ConsentRules
{
    /** @return list<string> */
    public static function pd(bool $checkout = false): array
    {
        $flag = $checkout ? 'features.checkout_pd_consent_enforce' : 'features.pd_consent_enforce';

        return config($flag) ? ['accepted'] : ['nullable'];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'pd_consent.accepted' => 'Нужно согласие на обработку персональных данных.',
        ];
    }
}
