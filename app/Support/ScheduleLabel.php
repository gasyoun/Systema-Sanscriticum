<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Единое оформление подписей на публичной /raspisanie (MG 28-09-2026):
 *
 * 1. «(2026)» в текущем году не пишем — год остаётся только чтобы отличить
 *    курсы разных лет (важно для записей в продаже).
 * 2. Дни недели — двухбуквенными аббревиатурами («вс 10:00»).
 * 3. Преподаватели — всегда «Имя Отчество Фамилия»
 *    («Лейтан Эдгар Зигфридович» → «Эдгар Зигфридович Лейтан»).
 * 4. В оглавлении и свёрнутом заголовке у каждого курса — день и время
 *    ближайшего занятия плюс «сейчас N-е (нед. W)»: номер идущего занятия
 *    и номер недели года.
 * 5. Предмет грамматики называем явно: «Грамматика по Кочергиной гр.61»
 *    → «Грамматика санскрита по Кочергиной №61» (грамматика бывает и хинди).
 *
 * Чистый форматтер без БД: названия курсов в БД не трогаем, только показ.
 * Ссылки на страницы преподавателей строятся по исходному имени.
 */
final class ScheduleLabel
{
    /** Именительный падеж → аббревиатура; индекс = Carbon dayOfWeekIso. */
    public const WEEKDAY_ABBR = [
        1 => 'пн',
        2 => 'вт',
        3 => 'ср',
        4 => 'чт',
        5 => 'пт',
        6 => 'сб',
        7 => 'вс',
    ];

    /**
     * Показное название курса: без текущего года, с предметом грамматики,
     * с аббревиатурами дней недели.
     */
    public static function displayTitle(string $title, ?int $currentYear = null): string
    {
        $year = $currentYear ?? (int) now()->format('Y');

        // «(2026)» в текущем году — долой; «(2025)», «(2025-2026)» остаются.
        $title = preg_replace_callback(
            '/\s*\((\d{4})\)/u',
            static fn (array $m): string => (int) $m[1] === $year ? '' : $m[0],
            $title,
        ) ?? $title;

        // Предмет грамматики + № вместо гр.
        $title = preg_replace(
            '/Грамматика по (\S+) гр\.\s*(\d+)/u',
            'Грамматика санскрита по $1 №$2',
            $title,
        ) ?? $title;
        $title = preg_replace(
            '/Грамматика хинди гр\.\s*(\d+)/u',
            'Грамматика хинди №$1',
            $title,
        ) ?? $title;

        // Дни недели целиком не пишем: «воскресенье» → «вс».
        $title = preg_replace_callback(
            '/\b(понедельник|вторник|среда|четверг|пятница|суббота|воскресенье)\b/iu',
            static fn (array $m): string => self::abbrFor(mb_strtolower($m[1])),
            $title,
        ) ?? $title;

        return trim(preg_replace('/\s{2,}/u', ' ', $title) ?? $title);
    }

    /**
     * «Лейтан Эдгар Зигфридович» → «Эдгар Зигфридович Лейтан».
     * Первое слово (фамилия) переезжает в конец; для двусловных имён
     * («Уша Санка» → «Санка Уша») правило то же.
     */
    public static function teacherDisplay(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name)) ?: [];
        if (count($parts) < 2) {
            return $name;
        }
        $parts[] = array_shift($parts);

        return implode(' ', $parts);
    }

    /** «пн 20:00» — день и время ближайшего занятия. */
    public static function nextLabel(?Carbon $next): ?string
    {
        if ($next === null) {
            return null;
        }

        return (self::WEEKDAY_ABBR[$next->dayOfWeekIso] ?? '').' '.$next->format('H:i');
    }

    /**
     * «сейчас 6-е (нед. 40)» — номер идущего занятия (прошедших + 1)
     * и номер недели года ближайшего занятия.
     */
    public static function progressLabel(int $pastTotal, ?Carbon $upcoming): ?string
    {
        if ($upcoming === null) {
            return null;
        }

        return 'сейчас '.($pastTotal + 1).'-е (нед. '.$upcoming->isoWeek.')';
    }

    private static function abbrFor(string $lowerWeekday): string
    {
        return match ($lowerWeekday) {
            'понедельник' => 'пн',
            'вторник' => 'вт',
            'среда' => 'ср',
            'четверг' => 'чт',
            'пятница' => 'пт',
            'суббота' => 'сб',
            default => 'вс',
        };
    }
}
