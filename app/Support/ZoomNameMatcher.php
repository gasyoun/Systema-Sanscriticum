<?php

declare(strict_types=1);

namespace App\Support;

/**
 * H3761 — сопоставление экранного имени в Zoom с пользователем платформы.
 *
 * Зачем вообще: Zoom отдаёт `user_email` только для участников, залогиненных в
 * тот же Zoom-аккаунт, поэтому почта есть у 4 % строк `webinar_attendances`, а
 * `user_id` не проставляется у остальных 96 %. Плашка покрытия считает людей —
 * и показывает ноль при сотнях собранных строк. Единственный оставшийся признак
 * — экранное имя.
 *
 * Почему не «имя равно `users.name`»: точное совпадение даёт 0 из 1218 строк.
 * Экранные имена приходят транслитом («Anna Ivanova»), в обратном порядке
 * («Иванова Анна»), с одним словом, с лишними словами. Поэтому сравниваются
 * НАБОРЫ ТОКЕНОВ, а латиница приводится к кириллице.
 *
 * Кандидаты всегда ограничены плательщиками одного курса (десятки, не тысячи):
 * на таком множестве совпадение по одному токену ещё осмысленно, на всей базе
 * пользователей оно было бы шумом.
 *
 * Уверенность различается и НЕ схлопывается: `strong` — совпали два токена
 * (имя и фамилия), `weak` — один. Неоднозначные и несопоставленные не
 * угадываются, а возвращаются человеку.
 */
class ZoomNameMatcher
{
    /**
     * Латиница → кириллица. Многобуквенные сочетания идут первыми: strtr()
     * берёт самый длинный ключ, поэтому «sh» не распадётся на «s»+«h».
     */
    private const TRANSLIT = [
        'shch' => 'щ', 'sch' => 'щ', 'yo' => 'ё', 'zh' => 'ж', 'kh' => 'х', 'ts' => 'ц',
        'ch' => 'ч', 'sh' => 'ш', 'yu' => 'ю', 'ya' => 'я', 'ye' => 'е', 'iy' => 'ий',
        'a' => 'а', 'b' => 'б', 'v' => 'в', 'g' => 'г', 'd' => 'д', 'e' => 'е', 'z' => 'з',
        'i' => 'и', 'j' => 'й', 'k' => 'к', 'l' => 'л', 'm' => 'м', 'n' => 'н', 'o' => 'о',
        'p' => 'п', 'r' => 'р', 's' => 'с', 't' => 'т', 'u' => 'у', 'f' => 'ф', 'y' => 'ы',
        'c' => 'к', 'h' => 'х', 'w' => 'в', 'x' => 'кс', 'q' => 'к',
    ];

    /** Токены короче этого — инициалы и предлоги, для сопоставления бесполезны. */
    private const MIN_TOKEN_LENGTH = 3;

    /**
     * Не имена, а подпись устройства или заглушка клиента Zoom. Из реальных
     * данных: «iPhone», «Galaxy», «Пользователь». Без этого списка «iPhone»
     * после транслита превращается в «ипхоне» и участвует в сопоставлении как
     * обычное слово — то есть может дать ложную связку.
     */
    private const DEVICE_WORDS = [
        'iphone', 'ipad', 'ipod', 'macbook', 'imac', 'android', 'samsung', 'galaxy',
        'redmi', 'xiaomi', 'huawei', 'honor', 'poco', 'realme', 'oppo', 'vivo',
        'nokia', 'lenovo', 'tablet', 'phone', 'user', 'guest', 'admin', 'zoom',
        'meeting', 'участник', 'пользователь', 'гость', 'телефон', 'айфон',
    ];

    /**
     * Нормализованный ключ имени — то, по чему хранится связка.
     * Токены сортируются, поэтому «Анна Иванова» и «Иванова Анна» дают один ключ.
     */
    public static function key(?string $raw): string
    {
        $tokens = self::tokens($raw);
        sort($tokens);

        return implode(' ', $tokens);
    }

    /**
     * Токены имени: нижний регистр, без пунктуации и цифр, латиница приведена
     * к кириллице. «iPhone», «79xx…» и прочие имена устройств дают пустой набор.
     *
     * @return list<string>
     */
    public static function tokens(?string $raw): array
    {
        $s = mb_strtolower(trim((string) $raw));
        $s = preg_replace('~[^\p{L}\s]+~u', ' ', $s) ?? '';
        $s = trim(preg_replace('~\s+~u', ' ', $s) ?? '');

        if ($s === '') {
            return [];
        }

        $out = [];
        foreach (explode(' ', $s) as $token) {
            if (mb_strlen($token) < self::MIN_TOKEN_LENGTH) {
                continue;
            }
            // Подпись устройства отсеивается ДО транслита: «iphone» иначе
            // становится «ипхоне» и выглядит как обычное слово.
            if (in_array($token, self::DEVICE_WORDS, true)) {
                continue;
            }
            // Латинское слово приводим к кириллице; смешанные и кириллические — как есть.
            $out[] = preg_match('~^[a-z]+$~', $token) === 1 ? strtr($token, self::TRANSLIT) : $token;
        }

        return array_values(array_unique($out));
    }

    /**
     * Сопоставить экранное имя со списком кандидатов.
     *
     * @param  array<int, string>  $candidates  user_id => users.name
     * @return array{user_id: ?int, confidence: ?string, reason: string}
     *                                                                   confidence: 'strong' (совпали 2+ токена) | 'weak' (1 токен) | null
     */
    public static function match(?string $zoomName, array $candidates): array
    {
        $zoomTokens = self::tokens($zoomName);
        if ($zoomTokens === []) {
            return ['user_id' => null, 'confidence' => null, 'reason' => 'имя не содержит слов (устройство или телефон)'];
        }

        $strong = [];
        $weak = [];

        foreach ($candidates as $userId => $userName) {
            $common = count(array_intersect($zoomTokens, self::tokens($userName)));
            if ($common >= 2) {
                $strong[] = (int) $userId;
            } elseif ($common === 1) {
                $weak[] = (int) $userId;
            }
        }

        if (count($strong) === 1) {
            return ['user_id' => $strong[0], 'confidence' => 'strong', 'reason' => 'совпали имя и фамилия'];
        }
        if (count($strong) > 1) {
            return ['user_id' => null, 'confidence' => null, 'reason' => 'несколько полных совпадений — решает человек'];
        }
        if (count($weak) === 1) {
            return ['user_id' => $weak[0], 'confidence' => 'weak', 'reason' => 'совпало одно слово'];
        }
        if (count($weak) > 1) {
            return ['user_id' => null, 'confidence' => null, 'reason' => 'несколько частичных совпадений — решает человек'];
        }

        return ['user_id' => null, 'confidence' => null, 'reason' => 'среди плательщиков курса совпадений нет'];
    }
}
