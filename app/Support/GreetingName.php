<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;

/**
 * Имя для обращения из свободного поля `users.name` / `leads.name`.
 *
 * Зачем. Уведомления здоровались полным полем: «Намасте, Мухасанова Хадижа
 * Абдурахмановна!», «Намасте, Иванов Иван, Москва!» — чекаут и пробное
 * склеивают в name «Фамилия Имя, Город», марафон и лиды пишут «Имя Фамилия».
 * Порядок разный, поэтому «первое слово» ошибалось бы у одной из групп.
 *
 * Порядок решения: чистка (скобки, город после запятой, хвост после тире,
 * цифры, ники) → слово из {@see GreetingNameDictionary} → слово перед
 * отчеством → «Фамилия Имя» по окончанию фамилии → первое слово.
 * Точное имя, когда оно известно, лежит в `users.greeting_name`
 * ({@see User::greetingName()}) и имеет приоритет.
 */
final class GreetingName
{
    private const PATRONYMIC = '/(ович|евич|ьич|овна|евна|ична|инична)$/u';

    private const SURNAME = '/(ов|ова|ев|ева|ин|ина|ын|ына|ский|ская|цкий|цкая|енко|ук|юк|ян|ых|их)$/u';

    public static function of(?string $full, string $fallback = 'Друг'): string
    {
        $words = self::words((string) $full);

        if ($words === []) {
            return $fallback;
        }

        return self::capitalize(self::pick($words));
    }

    /** @param  list<string>  $words */
    private static function pick(array $words): string
    {
        $lower = array_map(fn (string $w) => self::key($w), $words);

        foreach ($lower as $i => $w) {
            if (self::isKnownName($w)) {
                return $words[$i];
            }
        }

        foreach ($lower as $i => $w) {
            if ($i >= 1 && preg_match(self::PATRONYMIC, $w)) {
                return $words[$i - 1];
            }
        }

        if (count($words) === 2
            && preg_match(self::SURNAME, $lower[0])
            && ! preg_match(self::SURNAME, $lower[1])) {
            return $words[1];
        }

        return $words[0];
    }

    /** Имя из словаря; двойное «Анна-Мария» узнаётся по любой части. */
    private static function isKnownName(string $lower): bool
    {
        static $set = null;
        $set ??= array_flip(GreetingNameDictionary::NAMES);

        foreach (explode('-', $lower) as $part) {
            if (isset($set[$part])) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private static function words(string $full): array
    {
        $s = preg_replace('/\([^)]*\)/u', ' ', $full) ?? '';
        $s = explode(',', $s)[0];
        $s = preg_split('/\s[-–—]\s/u', $s)[0];
        $s = preg_replace('/\S+@\S+/u', ' ', $s) ?? '';

        preg_match_all('/\p{L}+(?:-\p{L}+)*/u', $s, $m);

        return array_values(array_filter($m[0], fn (string $w) => mb_strlen($w) > 1 || count($m[0]) === 1));
    }

    private static function key(string $word): string
    {
        return str_replace('ё', 'е', mb_strtolower($word));
    }

    private static function capitalize(string $word): string
    {
        return implode('-', array_map(
            fn (string $p) => mb_strtoupper(mb_substr($p, 0, 1)).mb_strtolower(mb_substr($p, 1)),
            explode('-', $word),
        ));
    }
}
