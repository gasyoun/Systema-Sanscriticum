<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Внутренние (staff/owner) аккаунты — никогда не считаются студентами.
 *
 * Рулинг MG 26-10-2026, verbatim: «@pe4kinsmart и @gasyoun никогда не
 * считать, ни тут ни вообще, это админы».
 *
 * Почему по id, а не по роли: хендл `pe4kinsmart` висит на ДВУХ строках —
 * `users.id 937` («SuperAdmin», role=super_admin) и `users.id 6767`
 * («Кравченко Иван, Севастополь», role=NULL). Фильтр по role/is_admin ловит
 * только первую, поэтому 6767 продолжает считаться студентом.
 *
 * Зеркало в Uprava: tools/internal_accounts.py.
 */
final class InternalAccounts
{
    /** @return array<int, int> */
    public static function ids(): array
    {
        /** @var array<int, int> $ids */
        $ids = config('internal_accounts.user_ids', []);

        return array_values(array_map('intval', $ids));
    }

    public static function isInternal(int|string|null $userId): bool
    {
        if ($userId === null || $userId === '') {
            return false;
        }

        return in_array((int) $userId, self::ids(), true);
    }
}
