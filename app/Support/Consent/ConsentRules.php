<?php

declare(strict_types=1);

namespace App\Support\Consent;

/**
 * Правила валидации галочек согласия на формах.
 *
 * pd() — согласие на обработку ПДн. На формах money-пути (заявки об оплате
 * банком/PayPal/от юрлица, оплата от преподавателя, пополнение баланса,
 * пробное) обязательность остаётся за флагом features.pd_consent_enforce —
 * отказ там стоит денег, а включают его отдельно и после проверки.
 *
 * pdForCheckout() — ОПЛАТА на /payment/create, где согласие обязательно ВСЕГДА
 * и для гостя, и для залогиненного студента (рулинг MG 24-11-2026): оферта это
 * акцепт договора, а согласие на ПДн — законное основание обработки
 * (152-ФЗ ст. 9), и строка «нажимая на кнопку» мелким шрифтом под кнопкой ни
 * тем, ни другим не является. Флаг CHECKOUT_PD_CONSENT_ENFORCE здесь больше не
 * читается — он остался браузерной подсказкой (required) у claim-форм.
 */
final class ConsentRules
{
    /** @return list<string> */
    public static function pd(bool $checkout = false): array
    {
        $flag = $checkout ? 'features.checkout_pd_consent_enforce' : 'features.pd_consent_enforce';

        return config($flag) ? ['accepted'] : ['nullable'];
    }

    /**
     * Согласие на ПДн на странице оплаты — обязательно, без флага.
     *
     * @return list<string>
     */
    public static function pdForCheckout(): array
    {
        return ['accepted'];
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
