<?php

declare(strict_types=1);

namespace App\Support;

/**
 * /s (статьи): словесные пути вместо query string — то же расширение
 * ShopCatalogUrl-паттерна (H3093, /online), теперь на статьи. Категория и
 * поиск живут в /s/{facets}, а не в ?category=/?q=; пагинация (?page=)
 * остаётся query-параметром — Laravel сам добавляет её к любому базовому
 * пути, отдельного паттерна не нужно.
 */
class ArticlesCatalogUrl
{
    public const FACET_ORDER = ['rubrika', 'poisk'];

    /**
     * @return array<string, string>|null
     */
    public static function parse(string $facets): ?array
    {
        $segments = array_values(array_filter(explode('/', trim($facets, '/')), fn ($s) => $s !== ''));

        if (count($segments) === 0 || count($segments) % 2 !== 0) {
            return null;
        }

        $result = [];
        for ($i = 0; $i < count($segments); $i += 2) {
            $key = $segments[$i];
            $value = $segments[$i + 1];

            if (! in_array($key, self::FACET_ORDER, true) || array_key_exists($key, $result) || $value === '') {
                return null;
            }

            $result[$key] = $value;
        }

        return $result;
    }

    public static function build(?string $categorySlug, ?string $search): string
    {
        $facets = [];

        if ($categorySlug !== null && $categorySlug !== '') {
            $facets['rubrika'] = $categorySlug;
        }

        if ($search !== null && $search !== '') {
            $facets['poisk'] = ShopCatalogUrl::encodeWords($search);
        }

        if ($facets === []) {
            return '/s';
        }

        $path = '/s';
        foreach (self::FACET_ORDER as $key) {
            if (isset($facets[$key])) {
                $path .= '/'.$key.'/'.$facets[$key];
            }
        }

        return $path;
    }
}
