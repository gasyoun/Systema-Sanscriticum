<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Стадия одной строки реестра исключений из учебного TG-чата (H2746).
 *
 * Порядок стадий — не декоративный: восстановление разрешено только из
 * FeeSettled, то есть после того, как погашен И долг, И взнос за ЭТОТ чат.
 * Обратный порядок («вернём, потом заплатит») — ровно та дыра, ради закрытия
 * которой правило и существует.
 */
enum ChatRemovalStatus: string
{
    /** Кандидат подтверждён оператором, но из чата ещё не исключён. */
    case Qualified = 'qualified';

    /** Исключён из чата (Wave 1 — руками оператора, кнопкой в «Должники»). */
    case Removed = 'removed';

    /** Курсовой долг погашен, взнос за чат — ещё нет. */
    case DebtSettled = 'debt_settled';

    /** Долг погашен и взнос закрыт (оплачен или прощён) — можно возвращать. */
    case FeeSettled = 'fee_settled';

    /** Оператор вернул студента в чат. Эпизод закрыт. */
    case Restored = 'restored';

    /** Основание отпало (ошибка оператора, спорный долг, чужой платёж). */
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Qualified => 'Кандидат подтверждён',
            self::Removed => 'Исключён из чата',
            self::DebtSettled => 'Долг погашен',
            self::FeeSettled => 'Долг и взнос закрыты',
            self::Restored => 'Возвращён в чат',
            self::Cancelled => 'Отменено',
        };
    }

    /** Эпизод ещё живой: занимает место, взнос по нему может быть должен. */
    public function isOpen(): bool
    {
        return ! in_array($this, [self::Restored, self::Cancelled], true);
    }

    /** Из этой стадии оператору разрешено вернуть студента в чат. */
    public function allowsRestoration(): bool
    {
        return $this === self::FeeSettled;
    }
}
