<?php

declare(strict_types=1);

namespace App\Support;

/**
 * ID пикселя VK Ads для витрины (config/analytics.php → vk_pixel).
 *
 * Возвращает null, если пиксель выключен или ID не число: значение
 * подставляется прямо в JS, поэтому ничего, кроме цифр, не пропускаем.
 */
final class ShopVkPixel
{
    public static function id(): ?string
    {
        if (! config('analytics.vk_pixel.enabled', true)) {
            return null;
        }

        $id = trim((string) config('analytics.vk_pixel.shop_pixel_id'));

        return ctype_digit($id) ? $id : null;
    }
}
