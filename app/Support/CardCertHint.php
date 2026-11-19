<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Подсказка про оплату картой после перехода НСПК на сертификаты Минцифры
 * (Точка, письмо от 18-11-2026, вступает 19-11-2026).
 *
 * Страница оплаты картой теперь на российском сертификате: Chrome/Safari/
 * Firefox без установленных сертификатов Минцифры показывают ошибку
 * «соединение не защищено», и студент бросает оплату. СБП через приложение
 * банка работает как обычно. Один текст — для чекаута и TG-напоминаний об
 * оплате. Флаг features.checkout_card_cert_hint (ВКЛ): только текст, деньги
 * и доступ не трогает; выключить, когда браузеры начнут доверять сертификатам.
 */
final class CardCertHint
{
    public const TEXT = 'Если при оплате картой браузер пишет «соединение не защищено» — выберите оплату по СБП в приложении банка или откройте страницу оплаты в Яндекс Браузере.';

    public const GUIDE_URL = 'https://merch.securepaytb.ru/manual/guide-pay-without-errors.pdf';

    public static function enabled(): bool
    {
        return (bool) config('features.checkout_card_cert_hint');
    }
}
