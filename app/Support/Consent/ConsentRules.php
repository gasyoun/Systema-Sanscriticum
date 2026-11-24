<?php

declare(strict_types=1);

namespace App\Support\Consent;

/**
 * Правила валидации галочек согласия на формах.
 *
 * На ОПЛАТЕ (checkout) два согласия обязательны жёстко, без флага (рулинг MG
 * 24-11-2026): без них платёж не создаётся — ни гостю, ни залогиненному
 * студенту. Оферта — это акцепт договора, а согласие на ПДн — законное
 * основание обработки (152-ФЗ ст. 9); строка «нажимая на кнопку, вы
 * соглашаетесь» мелким шрифтом под кнопкой ни тем, ни другим не является.
 *
 * На остальных формах обязательность согласия на ПДн остаётся за флагом
 * features.pd_consent_enforce: там галочка всё равно пишется в журнал
 * consents, но отказ от неё не должен ронять заявку или подписку.
 */
final class ConsentRules
{
    /** @return list<string> */
    public static function pd(bool $checkout = false): array
    {
        // Оплата — всегда обязательно (см. docblock класса).
        if ($checkout) {
            return ['accepted'];
        }

        return config('features.pd_consent_enforce') ? ['accepted'] : ['nullable'];
    }

    /**
     * Принятие публичной оферты — отдельный акт от согласия на ПДн и от
     * согласия на рассылку (38-ФЗ). На оплате обязательно всегда.
     *
     * @return list<string>
     */
    public static function oferta(): array
    {
        return ['accepted'];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'pd_consent.accepted' => 'Нужно согласие на обработку персональных данных.',
            'oferta_accepted.accepted' => 'Нужно принять условия публичной оферты.',
        ];
    }
}
