<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Прикидка «влезет ли декодированный растр в остаток memory_limit».
 *
 * Родилась в CoverToBannerImporter (перенос обложек, H2541-волна) и извлечена
 * сюда 16-08-2026, когда та же проверка понадобилась сборке ревью-PDF домашек:
 * студент сдал телефонные фото, синхронная сборка PDF съела 128 МБ FPM, и
 * вместо PDF вышла пятисотка. Держать две копии арифметики памяти нельзя —
 * разъехавшись, они дали бы две «правды» об одном пределе.
 *
 * Оценка сознательно грубая и консервативная: точнее, чем «упали по факту»,
 * и дешевле, чем декодировать вслепую. Байты-на-пиксель передаёт вызывающий,
 * потому что они зависят от движка: truecolor GD ≈ 4-5, Imagick Q16 ≈ 8.
 */
final class RasterImageMemory
{
    /** Действующий memory_limit процесса в байтах; null — потолка нет. */
    public static function limitBytes(): ?int
    {
        $raw = trim((string) ini_get('memory_limit'));
        if ($raw === '' || $raw === '-1') {
            return null;
        }

        $value = (int) $raw;
        $unit = strtolower(substr($raw, -1));

        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }

    /**
     * Влезет ли декод картинки width×height при данной цене пикселя.
     *
     * $bytesPerPixel уже должен включать запас на одновременно живущие копии
     * (исходник + холст): CoverToBannerImporter передаёт 4 × 2.2.
     */
    public static function fits(int $width, int $height, float $bytesPerPixel): bool
    {
        $limit = self::limitBytes();
        if ($limit === null) {
            return true;
        }

        $needed = (int) ($width * $height * $bytesPerPixel);

        return memory_get_usage(true) + $needed < $limit;
    }
}
