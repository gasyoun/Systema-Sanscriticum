<?php

declare(strict_types=1);

namespace App\Services\Design;

/**
 * Чем закончился перенос обложки витрины в слот баннера.
 *
 * Три класса исходов, и различать их важно: Imported — сделали; Failed —
 * сломалось, надо смотреть журнал; всё остальное — законный пропуск, о котором
 * человеку сообщают спокойно («переносить нечего»), а не как об ошибке.
 * Массовое действие складывает пропуски в счётчики по причинам, поэтому label()
 * написан строчными — он подставляется в перечисление после слова «Пропустили».
 */
enum CoverImportOutcome: string
{
    case Imported = 'imported';
    case SlotTaken = 'slot_taken';
    case NoCover = 'no_cover';
    case FileMissing = 'file_missing';
    case Unreadable = 'unreadable';
    case Unsupported = 'unsupported';
    case GdUnavailable = 'gd_unavailable';
    case TooSmall = 'too_small';
    case TooHeavy = 'too_heavy';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Imported => 'перенесено',
            self::SlotTaken => 'слот уже занят',
            self::NoCover => 'нет обложки витрины',
            self::FileMissing => 'файл обложки не найден на диске',
            self::Unreadable => 'не удалось прочитать картинку',
            self::Unsupported => 'формат картинки не поддержан',
            self::GdUnavailable => 'PHP собран без поддержки этого формата',
            self::TooSmall => 'картинка слишком мелкая',
            self::TooHeavy => 'картинка не влезает в лимит',
            self::Failed => 'ошибка при переносе',
        };
    }

    /** Законный пропуск: делать было нечего, чинить нечего. */
    public function isSkip(): bool
    {
        return $this !== self::Imported && $this !== self::Failed;
    }
}
