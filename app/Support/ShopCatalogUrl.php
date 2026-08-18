<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Каталог /online: словесные пути вместо query string (H3xxx — /online?cat[0]=3
 * читался как плохой SEO-слаг). Один канонический путь на состояние фильтров:
 * facet-пары в фиксированном порядке, категории — через categories.slug
 * (уже есть, ASCII), препод/поиск — сырой текст с '-' вместо пробелов
 * (транслитерация необратима: Latin-слаг нельзя развернуть обратно в
 * кириллицу для точного LIKE/=).
 */
class ShopCatalogUrl
{
    public const FACET_ORDER = ['kategoriya', 'format', 'uroven', 'prepodavatel', 'poisk'];

    /**
     * Разбирает хвост /online/{facets} на сырые значения по ключу.
     * Неизвестный ключ или нечётное число сегментов — null (роут отдаёт 404,
     * не тихо съезжает на пустой каталог).
     *
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

    /**
     * Канонический путь /online[/...] в фиксированном порядке FACET_ORDER —
     * одно состояние фильтров всегда даёт один и тот же URL, независимо от
     * порядка кликов.
     *
     * @param  string[]  $categorySlugs
     */
    public static function build(
        array $categorySlugs = [],
        string $format = '',
        string $level = '',
        ?string $teacherName = null,
        ?string $search = null,
    ): string {
        $facets = [];

        $categorySlugs = array_values(array_unique(array_filter($categorySlugs, fn ($s) => $s !== '')));
        if ($categorySlugs !== []) {
            sort($categorySlugs);
            $facets['kategoriya'] = implode(',', $categorySlugs);
        }

        if ($format !== '') {
            $facets['format'] = $format;
        }

        if ($level !== '') {
            $facets['uroven'] = $level;
        }

        if ($teacherName !== null && $teacherName !== '') {
            $facets['prepodavatel'] = self::encodeWords($teacherName);
        }

        if ($search !== null && $search !== '') {
            $facets['poisk'] = self::encodeWords($search);
        }

        if ($facets === []) {
            return '/online';
        }

        $path = '/online';
        foreach (self::FACET_ORDER as $key) {
            if (isset($facets[$key])) {
                $path .= '/'.$key.'/'.$facets[$key];
            }
        }

        return $path;
    }

    /** Пробелы -> '-' для пути; лишние пробелы схлопываются. Кириллица не трогается. */
    public static function encodeWords(string $raw): string
    {
        return str_replace(' ', '-', trim(preg_replace('/\s+/u', ' ', $raw) ?? ''));
    }

    /** Обратное к encodeWords(). Единственная потеря — дефис внутри исходного текста. */
    public static function decodeWords(string $segment): string
    {
        return str_replace('-', ' ', $segment);
    }
}
