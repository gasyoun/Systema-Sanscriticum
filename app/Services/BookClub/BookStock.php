<?php

declare(strict_types=1);

namespace App\Services\BookClub;

use App\Models\Book;

/**
 * Остаток книг каталога (H8412).
 *
 * books.stock — единственный источник остатка (Q6/Q9). Заказ РЕЗЕРВИРУЕТ
 * экземпляры при создании (в транзакции, под row-lock), а неудачная оплата
 * резерв возвращает. stock может уйти в минус — это осознанно: отрицательное
 * число и есть очередь «под заказ 2–3 недели», которую видно в админке.
 */
final class BookStock
{
    /** Зарезервировать N экземпляров под заказ (в текущей транзакции). */
    public function reserve(Book $book, int $quantity): void
    {
        $locked = Book::query()->lockForUpdate()->find($book->id);
        if (! $locked) {
            return;
        }

        $locked->decrement('stock', max(0, $quantity));
    }

    /** Вернуть резерв (оплата не состоялась). */
    public function release(Book $book, int $quantity): void
    {
        $locked = Book::query()->lockForUpdate()->find($book->id);
        if (! $locked) {
            return;
        }

        $locked->increment('stock', max(0, $quantity));
    }
}
