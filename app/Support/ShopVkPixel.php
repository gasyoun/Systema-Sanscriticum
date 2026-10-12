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

    /**
     * Кампанийные пиксели, сработавшие на этом пути витрины.
     *
     * config('analytics.vk_pixel.campaign_pixels'): каждый пиксель — отдельный
     * счётчик VK Ads под рекламную кампанию и живёт только на своих путях
     * (pageView + forward_goals там же). ID проходит ту же валидацию, что и
     * основной: значение уходит в JS, поэтому пусто/не-цифры = выключен.
     *
     * @param  string  $path  путь запроса — request()->path(): 'k/{slug}', 'checkout/{tariff}'
     * @return array<int, array{id: string, forward_goals: string[], click_goals: array<string, string>}>
     */
    public static function campaignPixelsForPath(string $path): array
    {
        $normalized = trim($path, '/');

        $matched = [];

        foreach ((array) config('analytics.vk_pixel.campaign_pixels', []) as $pixel) {
            if (! (bool) ($pixel['enabled'] ?? false)) {
                continue;
            }

            $id = trim((string) ($pixel['id'] ?? ''));
            if ($id === '' || ! ctype_digit($id)) {
                continue;
            }

            $paths = array_map(
                static fn (string $p): string => trim($p, '/'),
                (array) ($pixel['paths'] ?? []),
            );

            if (! in_array($normalized, $paths, true)) {
                continue;
            }

            // Клик-цели — только на click_paths (по умолчанию все пути пикселя):
            // на чекауте ссылки /checkout/* означают смену режима, не запись.
            $clickPaths = array_map(
                static fn (string $p): string => trim($p, '/'),
                (array) ($pixel['click_paths'] ?? $pixel['paths'] ?? []),
            );

            $matched[] = [
                'id' => $id,
                'forward_goals' => array_values(array_filter(
                    (array) ($pixel['forward_goals'] ?? []),
                    static fn ($goal): bool => is_string($goal) && $goal !== '',
                )),
                'click_goals' => in_array($normalized, $clickPaths, true)
                    ? array_filter(
                        (array) ($pixel['click_goals'] ?? []),
                        static fn ($selector, $goal): bool => is_string($goal) && $goal !== '' && is_string($selector) && $selector !== '',
                        ARRAY_FILTER_USE_BOTH,
                    )
                    : [],
            ];
        }

        return $matched;
    }
}
