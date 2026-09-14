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
     * H3772 — складка алфавита для НЕЧЁТКОГО слоя.
     *
     * Транслитерация из латиницы промахивается систематически и ровно по этим
     * буквам: `Olga` даёт «олга» против «ольга», `Yulia` — «юлиа» против «юлия»,
     * `Sergey` — «сергеы» против «сергей». Выписывать это правилами — бесконечный
     * список исключений; дешевле убрать различия, которые в русских именах почти
     * не смыслоразличительны, и добить остаток расстоянием.
     *
     * Складка применяется ТОЛЬКО в нечётком слое. Точное совпадение по-прежнему
     * считается по несложенным токенам, иначе `strong` размылся бы.
     */
    private const FOLD = [
        // Систематические окончания женских имён: «Юлия»/`Yulia`, «Мария»/`Maria`,
        // «Наталья»/`Natalia` расходятся всегда и одинаково. Это правило, а не
        // опечатка, поэтому оно живёт здесь, а не в допуске расстояния: у
        // четырёхбуквенных имён допуск равен нулю намеренно (иначе «Анна» и
        // «Инна» стали бы одним человеком).
        'ия' => 'иа', 'ья' => 'иа',
        'ь' => '', 'ъ' => '', 'ё' => 'е', 'й' => 'и', 'ы' => 'и', 'э' => 'е',
    ];

    /**
     * Уменьшительное → полное имя. В Zoom подписываются тем, как зовут в жизни,
     * а в кабинете лежит паспортное.
     *
     * Неоднозначные по полу («саша», «женя», «валя») намеренно НЕ раскрыты: они
     * дали бы двух кандидатов и всё равно ушли бы человеку, но по дороге могли
     * бы вытеснить верное совпадение по фамилии.
     */
    private const DIMINUTIVES = [
        'катя' => 'екатерина', 'маша' => 'мария', 'настя' => 'анастасия',
        'лена' => 'елена', 'таня' => 'татьяна', 'оля' => 'ольга',
        'дима' => 'дмитрий', 'миша' => 'михаил', 'коля' => 'николай',
        'юля' => 'юлия', 'ира' => 'ирина', 'света' => 'светлана',
        'наташа' => 'наталья', 'галя' => 'галина', 'надя' => 'надежда',
        'соня' => 'софия', 'костя' => 'константин', 'серёжа' => 'сергей',
        'сережа' => 'сергей', 'паша' => 'павел', 'гриша' => 'григорий',
        'лёша' => 'алексей', 'леша' => 'алексей', 'вася' => 'василий',
        'люба' => 'любовь', 'ксюша' => 'ксения', 'даша' => 'дарья',
        'лиза' => 'елизавета', 'тоня' => 'антонина', 'андрюша' => 'андрей',
        'володя' => 'владимир', 'вова' => 'владимир', 'рита' => 'маргарита',
        'зина' => 'зинаида', 'слава' => 'вячеслав', 'боря' => 'борис',
        'тома' => 'тамара', 'алла' => 'алла', 'аня' => 'анна',
    ];

    /**
     * Допустимое расстояние Левенштейна по длине сложенного токена.
     * Короткие слова слишком легко превратить друг в друга: «вера» и «вика» —
     * это две буквы, но два разных человека. Поэтому до 4 символов допуск ноль.
     */
    private const DISTANCE_BUDGET = [4 => 0, 7 => 1, PHP_INT_MAX => 2];

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
     *                                                                   confidence: 'strong' (совпали 2+ токена точно) | 'weak' (1 точный токен)
     *                                                                   | 'fuzzy' (совпало через складку/уменьшительное/опечатку) | null
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

        $fuzzy = self::fuzzyMatch($zoomTokens, $candidates);

        // Два слова, совпавших через складку, надёжнее одного слова буква в
        // букву: «Yulia Petrova» против «Юлия Петрова» — это имя И фамилия,
        // тогда как точный слой видит только «петрова» и с тем же успехом
        // указал бы на однофамилицу. Поэтому нечёткое совпадение по двум
        // словам имеет приоритет над точным по одному.
        if ($fuzzy['user_id'] !== null && ($fuzzy['matched'] ?? 0) >= 2) {
            unset($fuzzy['matched']);

            return $fuzzy;
        }

        if (count($weak) === 1) {
            return ['user_id' => $weak[0], 'confidence' => 'weak', 'reason' => 'совпало одно слово'];
        }
        if (count($weak) > 1) {
            return ['user_id' => null, 'confidence' => null, 'reason' => 'несколько частичных совпадений — решает человек'];
        }

        unset($fuzzy['matched']);

        return $fuzzy;
    }

    /**
     * Нечёткий слой: складка алфавита + уменьшительные + расстояние Левенштейна.
     *
     * Возвращает `fuzzy` только при ЕДИНСТВЕННОМ кандидате с минимальной
     * суммарной ценой. Ничья — это отказ, а не выбор первого: ложная связка
     * даёт уверенно неверную цифру в отчёте бухгалтера, что хуже пустой клетки.
     *
     * @param  list<string>  $zoomTokens
     * @param  array<int, string>  $candidates
     * @return array{user_id: ?int, confidence: ?string, reason: string}
     */
    private static function fuzzyMatch(array $zoomTokens, array $candidates): array
    {
        $zoom = array_map([self::class, 'fold'], $zoomTokens);

        $best = null;         // [cost, matchedTokens]
        $bestUsers = [];

        foreach ($candidates as $userId => $userName) {
            $cand = array_map([self::class, 'fold'], self::tokens($userName));
            if ($cand === []) {
                continue;
            }

            $matched = 0;
            $cost = 0;
            $used = [];

            foreach ($zoom as $zt) {
                $localBest = null;
                $localKey = null;
                foreach ($cand as $k => $ct) {
                    if (isset($used[$k])) {
                        continue;
                    }
                    $d = self::distance($zt, $ct);
                    if ($d !== null && ($localBest === null || $d < $localBest)) {
                        $localBest = $d;
                        $localKey = $k;
                    }
                }
                if ($localBest !== null) {
                    $matched++;
                    $cost += $localBest;
                    $used[$localKey] = true;
                }
            }

            if ($matched === 0) {
                continue;
            }

            // Больше совпавших слов важнее меньшей цены: имя+фамилия с одной
            // опечаткой надёжнее, чем одно слово буква в букву.
            $rank = [-$matched, $cost];
            if ($best === null || $rank < $best) {
                $best = $rank;
                $bestUsers = [(int) $userId];
            } elseif ($rank === $best) {
                $bestUsers[] = (int) $userId;
            }
        }

        if ($best === null) {
            return ['user_id' => null, 'confidence' => null, 'matched' => 0, 'reason' => 'среди плательщиков курса совпадений нет'];
        }
        if (count($bestUsers) > 1) {
            return ['user_id' => null, 'confidence' => null, 'matched' => 0, 'reason' => 'нечёткое совпадение неоднозначно — решает человек'];
        }

        [$negMatched, $cost] = $best;
        $matched = -$negMatched;

        // Одно слово И с опечаткой — слишком тонко, чтобы кого-то опознать:
        // неточность и единственность признака складываются. Отказ.
        if ($matched < 2 && $cost > 0) {
            return [
                'user_id' => null, 'confidence' => null, 'matched' => 0,
                'reason' => 'одно неточное слово — слишком мало, чтобы опознать',
            ];
        }

        // Одно слово, совпавшее точно после складки («Olga» → «Ольга»), — это
        // ровно та же тонкость, что и обычный `weak`, поэтому и уровень тот же:
        // включается тем же флагом, а не проходит как нечёткое.
        if ($matched < 2) {
            return [
                'user_id' => $bestUsers[0], 'confidence' => 'weak', 'matched' => 1,
                'reason' => 'совпало одно слово (после складки транслита)',
            ];
        }

        return [
            'user_id' => $bestUsers[0],
            'confidence' => 'fuzzy',
            'matched' => $matched,
            'reason' => sprintf('нечётко: совпало слов %d, расхождение %d символ(ов)', $matched, $cost),
        ];
    }

    /**
     * Складка одного токена: раскрытие уменьшительного, затем снятие различий,
     * по которым транслитерация систематически промахивается.
     */
    public static function fold(string $token): string
    {
        $token = self::DIMINUTIVES[$token] ?? $token;

        return strtr($token, self::FOLD);
    }

    /**
     * Расстояние Левенштейна между сложенными токенами, либо null, если оно
     * выходит за допуск для этой длины.
     *
     * Своя реализация, а не `levenshtein()`: встроенная функция байтовая, а
     * кириллица в UTF-8 занимает два байта на букву — на ней она считает
     * мусор, причём молча.
     */
    public static function distance(string $a, string $b): ?int
    {
        if ($a === $b) {
            return 0;
        }

        $budget = self::budgetFor(min(mb_strlen($a), mb_strlen($b)));
        if ($budget === 0) {
            return null; // равенство уже проверено выше
        }
        if (abs(mb_strlen($a) - mb_strlen($b)) > $budget) {
            return null;
        }

        $aChars = mb_str_split($a);
        $bChars = mb_str_split($b);
        $prev = range(0, count($bChars));

        foreach ($aChars as $i => $ac) {
            $curr = [$i + 1];
            foreach ($bChars as $j => $bc) {
                $curr[$j + 1] = min(
                    $prev[$j + 1] + 1,
                    $curr[$j] + 1,
                    $prev[$j] + ($ac === $bc ? 0 : 1),
                );
            }
            // Ранний выход: вся строка уже дороже допуска.
            if (min($curr) > $budget) {
                return null;
            }
            $prev = $curr;
        }

        $d = $prev[count($bChars)];

        return $d <= $budget ? $d : null;
    }

    /** Допуск расстояния для длины слова. */
    private static function budgetFor(int $length): int
    {
        foreach (self::DISTANCE_BUDGET as $upTo => $budget) {
            if ($length <= $upTo) {
                return $budget;
            }
        }

        return 0;
    }
}
