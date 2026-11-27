<?php

declare(strict_types=1);

namespace App\Services\BookClub;

use App\Models\Book;

/**
 * Цены витрины магазина «Книжный клуб» (H8412).
 *
 * Правила грилла 17-11-2026:
 *  - книга «Школьные лекции» — 1000 ₽ за экземпляр (Q5: цена нашего остатка
 *    тиража кампании 219924, не наценка); цена живёт в books.price;
 *  - посылка — до 2 книг, поэтому посылок = ceil(N/2) (Q2/Q13);
 *  - доставка РФ 300 ₽ ЗА ПОСЫЛКУ, заграница — тариф страны за посылку
 *    (config/book_club.php), самовывоз 0 ₽ (Q15);
 *  - 5+ книг принимаем, сумму согласуем письмом (порог — manual_quote_threshold).
 *
 * Никаких скидок/промо: это физический товар, не учебный продукт.
 */
final class BookClubShopPricing
{
    /** Сколько посылок нужно на N книг (не меньше одной). */
    public static function parcelCount(int $books): int
    {
        $perParcel = max(1, (int) config('book_club.max_books_per_parcel', 2));

        return max(1, (int) ceil(max(1, $books) / $perParcel));
    }

    /** Доставка в рублях: 0 при самовывозе, иначе тариф страны × число посылок. */
    public static function deliveryFor(?string $countryCode, int $books, bool $pickup = false): float
    {
        if ($pickup) {
            return 0.0;
        }

        return BookClubPricing::priceFor($countryCode) * self::parcelCount($books);
    }

    /** Сумма настолько велика, что её согласуем с покупателем письмом (5+ книг). */
    public static function requiresManualQuote(int $books): bool
    {
        return $books >= (int) config('book_club.manual_quote_threshold', 5);
    }

    /** Сумма в рублях для витрины: без потери копеек, но и без «,00». */
    public static function rub(float $value): string
    {
        $formatted = number_format($value, 2, ',', ' ');
        $formatted = rtrim(rtrim($formatted, '0'), ',');

        return $formatted === '' ? '0' : $formatted;
    }

    /**
     * Полный расчёт заказа — единственный источник суммы для витрины, контроллера
     * и тестов.
     *
     * @return array{books:int, unit_price:float, books_total:float, parcels:int, delivery:float, total:float, manual:bool}
     */
    public static function quote(Book $book, int $books, ?string $countryCode, bool $pickup = false): array
    {
        $books = max(1, $books);
        $unit = $book->priceRub();
        $booksTotal = $unit * $books;
        $delivery = self::deliveryFor($countryCode, $books, $pickup);

        return [
            'books' => $books,
            'unit_price' => $unit,
            'books_total' => $booksTotal,
            'parcels' => self::parcelCount($books),
            'delivery' => $delivery,
            'total' => $booksTotal + $delivery,
            'manual' => self::requiresManualQuote($books),
        ];
    }
}
