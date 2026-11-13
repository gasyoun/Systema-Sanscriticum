<?php

declare(strict_types=1);

namespace App\Services\BookClub;

/**
 * Тарифы доставки «Книжного клуба» (кампания planeta 219924, H8195).
 *
 * Единственный источник цены доставки на чекауте: РФ — фиксированная цена
 * посылки, заграница — по стране из таблицы config/book_club.php (сверено с
 * book-club-219924/README.md). Никаких скидок/лояльности/промо — доставка
 * не является учебным продуктом.
 */
final class BookClubPricing
{
    /**
     * Цена доставки в рублях для страны из формы чекаута.
     *
     * 'RU' (или пусто) — РФ; 'OTHER' и любой неизвестный код — other_price.
     */
    public static function priceFor(?string $countryCode): float
    {
        $code = mb_strtoupper(trim((string) $countryCode));

        if ($code === '' || $code === 'RU' || $code === 'RF') {
            return (float) config('book_club.rf_price');
        }

        $prices = (array) config('book_club.abroad_prices', []);

        return (float) ($prices[$code] ?? config('book_club.other_price'));
    }

    /** Это доставка за рубеж (PayPal-путь), а не РФ (Tochka/СБП)? */
    public static function isAbroad(?string $countryCode): bool
    {
        $code = mb_strtoupper(trim((string) $countryCode));

        return $code !== '' && $code !== 'RU' && $code !== 'RF';
    }

    /** Коды стран чекаута (для валидации Rule::in). */
    public static function countryCodes(): array
    {
        return array_keys((array) config('book_club.countries', []));
    }
}
