<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\User;

/**
 * H6090 / ростер v2, гейт Q5 (pii-guard, фаза 0): маскинг личностей студента
 * ДО любого LLM-вызова в suggester-шве.
 *
 * Тот же контракт, что tools/school_pii_guard.py в Uprava (05-10-2026):
 * identities — живой сет users (name, email, phone, telegram_username, vk_id;
 * значения короче 4 символов и allow-лист исключены), матчинг — standalone-токен
 * (?<!\w)…(?!\w) с /u (кириллица внутри \w, поэтому «Подписчики» не ловится за
 * «Подписчик»), плюс структурные фигуры email/телефона; теги <PII:…:N> —
 * псевдонимы, сквозная нумерация на вызов; значения идентичностей не
 * логируются и не кэшируются между инстансами.
 *
 * H6144 (фикс 1 по вердикту H6140 на PR #3027): телефонные значения и фигуры
 * матчатся ЦИФРОВОЙ нормализацией — '+7 (916) 123-45-67', '8-916-123-45-67'
 * и '+79161234567' это одна и та же личность (префикс +7/8 взаимозаменяем,
 * разделители между цифрами необязательны); канонический ключ тега —
 * нормализованные цифры, поэтому любое написание получает один тег.
 *
 * H6918/гейт H5562 (18-10-2026, name-aware слой): сэмпл 30 диалогов поддержки
 * дал FAIL — 15 пропусков класса 1 из 24: маскер ловил только личности из
 * таблицы users, а имена нелинкованных студентов, диминутивы («Маша») и
 * хэндлы вида `@masha_v` уходили в текст сырыми. Добавлены четыре слоя БЕЗ
 * NER и без сети:
 *  1) @-хэндл-shaped фигура (`@masha_v`) — класс «контакт», любой, кроме allow;
 *  2) само-представление («меня зовут X») — имя маскируется, префикс остаётся;
 *  3) газетир русских личных имён (имен. падеж + диминутивы) с опциональной
 *     фамилией/отчеством-фигурой во втором токене — «Мария Ковалёва» = ОДИН тег;
 *     отдельно — standalone отчество (`…ович/…овна`), precision-safe;
 *  4) per-conversation словарь имён/ников — bare-фамилия («Ковалёва просила…»)
 *     и ник без @ (`masha_v сказала…`), см. ниже.
 * Плата за это — осознанное over-redaction: имя-омоним с заглавной буквы
 * («Роман», «Вера» в начале фразы) тоже маскируется — сторона fail-closed.
 *
 * H6918-2 (18-10-2026, per-conversation словарь): слой 4 закрывает остаток
 * там, где имя вообще есть в беседе или в профиле участника; собирается из
 * двух детерминированных источников:
 *  a) участники беседы — display name и username контакта, имя связанного
 *     студента — через seedConversation();
 *  b) всё, что встретилось в тексте беседы: полное имя, bare-имя/фамилия,
 *     само-представление, @-хэндл — через learn()/maskAll() ДО маскирования.
 * Поэтому «Мария Ковалёва» в одном сообщении делает bare «Ковалёва», а
 * `@masha_v` — bare «masha_v» словарными токенами для всех следующих
 * сообщений. Словарь живёт РОВНО одну беседу: newConversation() его чистит,
 * между чатами он не переиспользуется (иначе имя из прошлого разговора
 * замаскировало бы слово в новом). Остаток вне словаря: имя, которое нигде
 * в беседе не названо и отсутствует в профиле участника, детерминированно
 * поймать нечем — это граница метода, а не забытый слой.
 * PASS гейта H5562 по-прежнему требует нового 30-кейсового сэмпла: вердикт
 * FAIL 18-10-2026 этим слоем не переписывается.
 *
 * H5562, класс 2 (22-10-2026, канарейка вердикта): платёжные/заказные
 * идентификаторы. Метод гейта требует для класса 2 плейсхолдер `[ORDER]`, а в
 * маскере не было НИ ОДНОГО слоя класса 2: «заказ № 482913» уходил в LLM
 * сырым (канарейка: телефон и e-mail закрыты, номер заказа — нет). Зелёный
 * «класс 2 — 3/3» в сэмпле 18-10 держался на over-redaction сумм (совпадения
 * с числовыми личностями и телефонными фигурами), а не на покрытии номеров.
 * Поэтому слой намеренно CUE-якорный и даёт тег `<PII:order:N>` (рантайм-вид
 * `[ORDER]`): (а) «существительное идентификатора + номер» — заказ, платёж,
 * счёт, транзакция, инвойс, чек, order/payment/invoice/receipt; (б) голый
 * «№ 482913». Голая сумма без такого якоря («3 500 ₽», «оплатил 12000») НЕ
 * маскируется — сумма не идентификатор, и в следующем сэмпле она не должна
 * считаться попаданием класса 2, иначе вердикт снова станет ложным.
 *
 * Fail-closed: если identity-сет недоступен (БД упала), maskOrFail() бросает —
 * вызывающий ОБЯЗАН исключить текст из промпта (черновик собирается из одних
 * фактов), а не отправлять сырой текст. Чистоту нельзя доказать → вызова нет.
 */
class SupportStudentPiiMasker
{
    /** Хэндл владельца контура встречается в текстах легитимно и личностью не является. */
    private const ALLOW = ['gasyoun'];

    private const STRUCT_EMAIL = '/[\w.+-]+@[\w-]+\.[\w.]{2,}/u';

    /** H6144: разделители между цифрами не меняют фигуру телефона. */
    private const STRUCT_PHONE = '/(?<!\d)(?:\+7|8)(?:[ \x{00A0}\t.()+\-]*\d){10}(?!\d)/u';

    /** Класс разделителей между цифрами телефонной личности (H6144). */
    private const PHONE_SEP = '[ \x{00A0}\t.()+\-]';

    /** Значение состоит только из цифр и телефонных разделителей. */
    private const PHONE_LIKE = '/^[+\d \x{00A0}\t.()+\-]+$/u';

    /**
     * Класс 2 (H5562, метод: `[ORDER]`): cue-якорь — существительное
     * идентификатора (заказ/платёж/счёт/транзакция/инвойс/чек, order/payment/
     * invoice/receipt) + разделители + номер 4–12 цифр. `(*SKIP)(*F)` держит
     * уже поставленные `<PII:…>`-теги вне матча.
     */
    private const STRUCT_ORDER_CUE = '/(?:<PII:[a-z\-]+:\d+>(*SKIP)(*F))|(?<!\p{L})(?:заказ\p{L}*|плат[её]ж\p{L}*|сч[её]т\p{L}*|транзакци\p{L}*|инвойс\p{L}*|чек\p{L}*|orders?|payments?|invoices?|receipts?)(?:[ \t]+(?:id|no\.?|number))?[^\d\p{L}]{0,24}(?P<id>\d{4,12})(?!\d)/iu';

    /** Класс 2: голый номер со знаком № — «оплата № 4812». */
    private const STRUCT_ORDER_NO = '/(?:<PII:[a-z\-]+:\d+>(*SKIP)(*F))|(?<!\p{L})№[ \t]*(?P<id>\d{4,12})(?!\d)/u';

    /** Класс 1, слой 1: @-хэндл любой (не только из users) — контакт. */
    private const STRUCT_HANDLE = '/(?<![\w@])@([A-Za-z][A-Za-z0-9_]{2,})(?!\w)/u';

    /** Класс 1, слой 2: само-представление — имя после префикса, префикс сохраняется. */
    private const SELF_INTRO = '/(?P<pre>(?i:меня\s+зовут|моё\s+имя|мое\s+имя|зовут\s+меня)\s+)(?P<name>[\p{Lu}][\p{Ll}\-]{1,})/u';

    /** Класс 1, слой 3b: standalone отчество — точная фигура, ложных срабатываний почти нет. */
    private const PATRONYMIC_ONLY = '/(?<!\p{L})\p{Lu}\p{Ll}+(?:ович|евич|ьич|овна|евна|ична|инична)(?!\p{L})/u';

    /** Второй токен имени: отчество-фигура. */
    private const PATRONYMIC = '\p{Lu}\p{Ll}+(?:ович|евич|ьич|овна|евна|ична|инична)';

    /** Второй токен имени: фамилия-фигура (только после имени из газетира). */
    private const SURNAME = '\p{Lu}\p{Ll}+(?:ова|ева|ёва|ина|ына|ская|цкая|ский|цкий|ой|ов|ев|ёв|ин|ын)';

    /**
     * Класс 1, слой 3a: газетир русских личных имён — имен. падеж + диминутивы.
     * Матч ЧУВСТВИТЕЛЕН к регистру (только с заглавной): «роман» как жанр и
     * «подписчики» не задеваются. Цена — омонимы с заглавной («Роман», «Вера»)
     * маскируются: это сторона fail-closed, см. docblock.
     */
    private const FIRST_NAMES = [
        'Александр', 'Александра', 'Алексей', 'Алина', 'Алиса', 'Алла', 'Алёша', 'Алеша', 'Анастасия',
        'Анатолий', 'Ангелина', 'Андрей', 'Анжела', 'Анна', 'Антон', 'Антонина', 'Аня', 'Арина', 'Аркадий',
        'Арсений', 'Артём', 'Артем', 'Артур', 'Богдан', 'Борис', 'Боря', 'Вадим', 'Валентин', 'Валентина',
        'Валерий', 'Валерия', 'Валя', 'Ваня', 'Варвара', 'Василий', 'Василиса', 'Вася', 'Вера', 'Вероника',
        'Вика', 'Виктор', 'Виктория', 'Виталий', 'Витя', 'Владимир', 'Владислав', 'Влад', 'Влада', 'Вова',
        'Всеволод', 'Вячеслав', 'Галина', 'Галя', 'Геннадий', 'Гена', 'Георгий', 'Гера', 'Герман', 'Глеб',
        'Гоша', 'Григорий', 'Гриша', 'Даниил', 'Данил', 'Дарья', 'Даша', 'Денис', 'Диана', 'Дина', 'Дмитрий',
        'Дима', 'Ева', 'Евгений', 'Евгения', 'Егор', 'Екатерина', 'Елена', 'Елизавета', 'Ефим', 'Жанна',
        'Женя', 'Жора', 'Захар', 'Зинаида', 'Зоя', 'Иван', 'Игнат', 'Игорь', 'Илья', 'Инна', 'Иннокентий',
        'Ира', 'Ирина', 'Карина', 'Катя', 'Кира', 'Кирилл', 'Клава', 'Клавдия', 'Клим', 'Коля', 'Константин',
        'Костя', 'Кристина', 'Ксения', 'Ксюша', 'Лариса', 'Лев', 'Лена', 'Лера', 'Леонид', 'Лида', 'Лидия',
        'Лиза', 'Лилия', 'Люба', 'Любовь', 'Люда', 'Людмила', 'Лёня', 'Леня', 'Лёша', 'Леша', 'Максим',
        'Маргарита', 'Марина', 'Мария', 'Марк', 'Марья', 'Матвей', 'Маша', 'Мила', 'Милана', 'Михаил',
        'Миша', 'Надежда', 'Надя', 'Настя', 'Наталия', 'Наталья', 'Наташа', 'Ника', 'Никита', 'Николай',
        'Нина', 'Оксана', 'Олег', 'Олеся', 'Ольга', 'Оля', 'Павел', 'Паша', 'Пётр', 'Петр', 'Петя', 'Платон',
        'Полина', 'Прохор', 'Раиса', 'Рая', 'Регина', 'Римма', 'Родион', 'Роза', 'Роман', 'Рома', 'Ростислав',
        'Руслан', 'Сабина', 'Савелий', 'Света', 'Светлана', 'Святослав', 'Семён', 'Семен', 'Сеня', 'Серафима',
        'Сергей', 'Серёжа', 'Сережа', 'Слава', 'Снежана', 'Соня', 'София', 'Софья', 'Станислав', 'Станислава',
        'Степан', 'Стёпа', 'Степа', 'Стелла', 'Таисия', 'Тамара', 'Таня', 'Тарас', 'Татьяна', 'Тимофей',
        'Тимур', 'Тихон', 'Толя', 'Тёма', 'Тема', 'Ульяна', 'Уля', 'Фёдор', 'Федор', 'Филипп', 'Эвелина',
        'Эдуард', 'Элеонора', 'Эльвира', 'Эмилия', 'Юлия', 'Юля', 'Юра', 'Юрий', 'Яков', 'Ян', 'Яна',
        'Ярослав', 'Ярослава', 'Яша',
    ];

    /** @var array<string, string>|null значение => поле (кэш на инстанс) */
    private ?array $identities = null;

    /** @var array<int, array{string, string, string}>|[поле, паттерн, канонический ключ] longest-first */
    private ?array $patterns = null;

    /** Лениво собранный паттерн газетира (H6918, слой 3a) — один на инстанс. */
    private ?string $nameLane = null;

    /**
     * H6918-2, слой 4: per-conversation словарь имён/фамилий и ников.
     * Ключ — lowercase-токен, значение — исходное написание для unmask().
     *
     * @var array<string, string>
     */
    private array $conversationPeople = [];

    /** @var array<string, string> */
    private array $conversationNicks = [];

    /** Лениво собранный паттерн словаря беседы — сбрасывается при каждом learn(). */
    private ?string $conversationLane = null;

    /** @var array<string, int> ключ (поле|значение или фигура|значение) => номер тега */
    private array $tags = [];

    /**
     * H6352-r3: номер тега => исходное значение в ТЕКУЩЕЙ области (begin()/mask()).
     * Нужен, чтобы вернуть псевдонимы в ответе внешней модели (<PII:name:1> →
     * «Анна Петрова») и не показать студенту служебный тег.
     *
     * @var array<int, string>
     */
    private array $values = [];

    /**
     * Начать новую область псевдонимов — один внешний вызов (все сообщения
     * промпта + ответ). Внутри области один и тот же человек получает один
     * тег, поэтому ответ модели можно восстановить через unmask().
     */
    public function begin(): void
    {
        $this->tags = [];
        $this->values = [];
    }

    /**
     * H6918-2: граница беседы. Очищает per-conversation словарь и область
     * псевдонимов; между чатами словарь НЕ переиспользуется, иначе имя из
     * прошлого разговора замаскировало бы слово в новом.
     */
    public function newConversation(): void
    {
        $this->conversationPeople = [];
        $this->conversationNicks = [];
        $this->conversationLane = null;
        $this->begin();
    }

    /**
     * H6918-2: засеять словарь беседы тем, что известно об участниках ВНЕ
     * текста — display name и username Telegram-контакта, имя связанного
     * студента. Полное имя даёт и отдельные токены: «Мария Ковалёва» из
     * профиля ловит позднее bare «Ковалёва».
     *
     * @param  list<string>  $names
     * @param  list<string>  $nicks
     */
    public function seedConversation(array $names = [], array $nicks = []): void
    {
        foreach ($names as $name) {
            $this->rememberPerson((string) $name);
        }
        foreach ($nicks as $nick) {
            $this->rememberNick((string) $nick);
        }
    }

    /**
     * H6918-2: запомнить имена/ники, встреченные в тексте беседы, ДО
     * маскирования. Двухпроходный режим: learn() по всем сообщениям, затем
     * mask() по каждому — «Мария Ковалёва» в первом сообщении делает
     * «Ковалёва» из второго словарным токеном. Ничего не маскирует и не
     * требует identity-сета, поэтому безопасен в offline-сэмпле.
     */
    public function learn(string $text): void
    {
        if (preg_match_all(self::STRUCT_HANDLE, $text, $matches) > 0) {
            foreach ($matches[1] as $nick) {
                $this->rememberNick((string) $nick);
            }
        }
        if (preg_match_all(self::SELF_INTRO, $text, $matches) > 0) {
            foreach ($matches['name'] as $name) {
                $this->rememberPerson((string) $name);
            }
        }
        if (preg_match_all($this->nameLane(), $text, $matches) > 0) {
            foreach ($matches[0] as $name) {
                $this->rememberPerson((string) $name);
            }
        }
        if (preg_match_all(self::PATRONYMIC_ONLY, $text, $matches) > 0) {
            foreach ($matches[0] as $name) {
                $this->rememberPerson((string) $name);
            }
        }
    }

    /**
     * H6918-2: маскировать несколько сообщений ОДНОЙ беседы как один проход.
     * Сначала learn() по всем текстам (словарь беседы), затем mask() по
     * каждому: имя, названное в первом сообщении, закрывает bare-фамилию/ник
     * в следующих. Нумерация тегов сквозная — ответ можно восстановить unmask().
     *
     * @param  list<string>  $texts
     * @return list<string>
     *
     * @throws \Throwable когда identity-сет недоступен (fail-closed, см. docblock)
     */
    public function maskAll(array $texts): array
    {
        $this->begin();
        foreach ($texts as $text) {
            $this->learn($text);
        }

        $masked = [];
        foreach ($texts as $text) {
            [$maskedText] = $this->mask($text);
            $masked[] = $maskedText;
        }

        return $masked;
    }

    /**
     * @return array{0: string, 1: int} замаскированный текст и число подстановок
     *
     * @throws \Throwable когда identity-сет недоступен (fail-closed, см. docblock)
     */
    public function maskOrFail(string $text): array
    {
        $this->begin();

        return $this->mask($text);
    }

    /**
     * H6352-r3: маскирование без сброса области — несколько сообщений одного
     * промпта нумеруются сквозняком. Перед первым сообщением вызывается begin().
     *
     * @return array{0: string, 1: int} замаскированный текст и число подстановок
     *
     * @throws \Throwable когда identity-сет недоступен (fail-closed, см. docblock)
     */
    public function mask(string $text): array
    {
        $this->ensureIdentities();
        $substitutions = 0;

        foreach ($this->patterns as [$field, $pattern, $canonical]) {
            $text = preg_replace_callback($pattern, function (array $m) use ($field, $canonical, &$substitutions): string {
                $substitutions++;

                return $this->tag($field, $canonical);
            }, $text);
        }

        foreach ([['email-shaped', self::STRUCT_EMAIL], ['phone-shaped', self::STRUCT_PHONE]] as [$kind, $pattern]) {
            $text = preg_replace_callback($pattern, function (array $m) use ($kind, &$substitutions): string {
                if (in_array($m[0], self::ALLOW, true)) {
                    return $m[0];
                }
                $substitutions++;

                return $this->tag($kind, $m[0]);
            }, $text);
        }

        // H5562, класс 2: платёжные/заказные идентификаторы — cue-якорь
        // («заказ», «счёт», «order»…) или голый «№ 482913». Голая сумма без
        // якоря не маскируется: она не идентификатор, и её over-redaction уже
        // один раз дала ложный зелёный по классу 2 (см. docblock).
        $text = $this->maskOrderIdentifiers($text, $substitutions);

        // H6918, слой 1: @-хэндл-shaped фигура — контакт, независимо от users.
        // H6918-2: ник запоминается без @ — bare «masha_v» в следующих
        // сообщениях беседы закроет словарь.
        $text = preg_replace_callback(self::STRUCT_HANDLE, function (array $m) use (&$substitutions): string {
            if (in_array($m[1], self::ALLOW, true)) {
                return $m[0];
            }
            $this->rememberNick($m[1]);
            $substitutions++;

            return $this->tag('handle', '@'.$m[1]);
        }, $text);

        // H6918, слой 2: само-представление — префикс остаётся, имя режется.
        $text = preg_replace_callback(self::SELF_INTRO, function (array $m) use (&$substitutions): string {
            if (in_array(mb_strtolower($m['name'], 'UTF-8'), self::ALLOW, true)) {
                return $m[0];
            }
            $this->rememberPerson($m['name']);
            $substitutions++;

            return $m['pre'].$this->tag('person', mb_strtolower($m['name'], 'UTF-8'), $m['name']);
        }, $text);

        // H6918-2, слой 4: словарь беседы — bare-фамилия и ник без @. Идёт
        // ПОСЛЕ @-хэндла (иначе ник разорвал бы @-фигуру) и ДО газетира,
        // чтобы засеянное полное имя («Мария Ковалёва») осталось одним тегом.
        $text = preg_replace_callback($this->conversationLane(), function (array $m) use (&$substitutions): string {
            $substitutions++;

            return $this->tag('person', mb_strtolower($m[0], 'UTF-8'), $m[0]);
        }, $text);

        // H6918, слой 3a: имя из газетира, опционально с фамилией/отчеством — один тег.
        $text = preg_replace_callback($this->nameLane(), function (array $m) use (&$substitutions): string {
            $this->rememberPerson($m[0]);
            $substitutions++;

            return $this->tag('person', mb_strtolower($m[0], 'UTF-8'), $m[0]);
        }, $text);

        // H6918, слой 3b: standalone отчество.
        $text = preg_replace_callback(self::PATRONYMIC_ONLY, function (array $m) use (&$substitutions): string {
            $this->rememberPerson($m[0]);
            $substitutions++;

            return $this->tag('person', mb_strtolower($m[0], 'UTF-8'), $m[0]);
        }, $text);

        return [$text, $substitutions];
    }

    /**
     * H6352-r3: вернуть псевдонимы ТЕКУЩЕЙ области в исходные значения — ответ
     * внешней модели идёт студенту, и служебные теги <PII:…:N> в нём недопустимы.
     * Чужие теги (созданные другим инстансом/областью) остаются как есть: это
     * граница доверия, а не глобальный словарь.
     */
    public function unmask(string $text): string
    {
        return (string) preg_replace_callback(
            '/<PII:([a-z\-]+):(\d+)>/u',
            fn (array $m): string => $this->values[(int) $m[2]] ?? $m[0],
            $text,
        );
    }

    /** Осталась ли в тексте standalone-личность или структурная фигура (round-trip гейт). */
    public function containsIdentity(string $text): bool
    {
        $this->ensureIdentities();
        foreach ($this->patterns as [, $pattern]) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }
        if (preg_match(self::STRUCT_EMAIL, $text) === 1 && ! in_array('', self::ALLOW, true)) {
            return true;
        }
        if (preg_match(self::STRUCT_PHONE, $text) === 1) {
            return true;
        }
        if (preg_match(self::STRUCT_ORDER_CUE, $text) === 1
            || preg_match(self::STRUCT_ORDER_NO, $text) === 1) {
            return true;
        }
        if (preg_match(self::STRUCT_HANDLE, $text, $handle) === 1 && ! in_array($handle[1], self::ALLOW, true)) {
            return true;
        }
        if (preg_match(self::SELF_INTRO, $text) === 1) {
            return true;
        }
        if (preg_match($this->conversationLane(), $text) === 1) {
            return true;
        }
        if (preg_match($this->nameLane(), $text) === 1) {
            return true;
        }
        if (preg_match(self::PATRONYMIC_ONLY, $text) === 1) {
            return true;
        }

        return false;
    }

    /**
     * Класс 2 (H5562): заменить ТОЛЬКО цифровую часть идентификатора — cue-слово
     * и «№» остаются, как префикс «меня зовут» в слое 2. Канонический ключ тега —
     * цифры, поэтому «заказ № 482913» и «заказ №482913» дают ОДИН псевдоним.
     */
    private function maskOrderIdentifiers(string $text, int &$substitutions): string
    {
        foreach ([self::STRUCT_ORDER_CUE, self::STRUCT_ORDER_NO] as $pattern) {
            $text = (string) preg_replace_callback($pattern, function (array $m) use (&$substitutions): string {
                $substitutions++;
                $tag = $this->tag('order', $m['id']);
                $pos = strpos($m[0], $m['id']);
                if ($pos === false) {
                    return $m[0];
                }

                return substr($m[0], 0, $pos).$tag.substr($m[0], $pos + strlen($m['id']));
            }, $text);
        }

        return $text;
    }

    /**
     * H6918, слой 3a: паттерн газетира. Границы — по букве (не по \w), иначе
     * «Иван» матчился бы внутри «Иванов»; второй токен (фамилия/отчество)
     * допускается ТОЛЬКО после имени из газетира — это держит precision.
     */
    private function nameLane(): string
    {
        if ($this->nameLane === null) {
            $names = implode('|', array_map(
                static fn (string $name): string => preg_quote($name, '/'),
                self::FIRST_NAMES,
            ));
            $this->nameLane = '/(?<!\p{L})(?:'.$names.')(?:[ \x{00A0}\t]+(?:'.self::PATRONYMIC.'|'.self::SURNAME.'))?(?!\p{L})/u';
        }

        return $this->nameLane;
    }

    /**
     * H6918-2, слой 4: паттерн словаря беседы. Токены длиннее первыми, чтобы
     * «Мария Ковалёва» съедалась целиком, а не за одной «Марией». Готовые
     * `<PII:…>`-теги пропускаются `(*SKIP)(*F)`: иначе ник, случайно равный
     * слову `person`, разорвал бы уже поставленный тег. Пустой словарь даёт
     * заведомо несовпадающий паттерн — regex остаётся валидным.
     */
    private function conversationLane(): string
    {
        if ($this->conversationLane !== null) {
            return $this->conversationLane;
        }

        $tokens = $this->conversationPeople + $this->conversationNicks;
        if ($tokens === []) {
            return $this->conversationLane = '/(?!)/u';
        }

        uksort($tokens, static fn (string $a, string $b): int => mb_strlen($b, 'UTF-8') <=> mb_strlen($a, 'UTF-8'));
        $alternation = implode('|', array_map(
            static fn (string $token): string => preg_quote($token, '/'),
            array_values($tokens),
        ));

        return $this->conversationLane = '/(?:<PII:[a-z\-]+:\d+>(*SKIP)(*F))|(?<!\p{L})(?:'.$alternation.')(?!\p{L})/iu';
    }

    /**
     * H6918-2: добавить в словарь беседы имя/фамилию. Полное имя кладётся
     * целиком И отдельными токенами — «Мария Ковалёва» даёт ещё и «Ковалёва»,
     * чтобы bare-фамилия в следующем сообщении не ушла сырой. Токены короче
     * 3 букв, не-имена (цифры, эмодзи) и allow-лист отбрасываются.
     */
    private function rememberPerson(string $value): void
    {
        $value = preg_replace('/^[\s\p{P}]+|[\s\p{P}]+$/u', '', $value) ?? '';
        if ($value === '' || mb_strlen($value, 'UTF-8') < 3
            || in_array(mb_strtolower($value, 'UTF-8'), self::ALLOW, true)) {
            return;
        }

        $parts = preg_split('/[\s\x{00A0}]+/u', $value) ?: [];
        $valid = [];
        foreach ($parts as $part) {
            if ($this->looksLikePerson($part)) {
                $valid[] = $part;
            }
        }

        if ($valid !== [] && count($valid) === count($parts)) {
            $this->addPersonToken($value);
        }
        foreach ($valid as $part) {
            $this->addPersonToken($part);
        }
    }

    /** Имя/фамилия-фигура: заглавная буква, дальше буквы/дефис, ≥3 символов. */
    private function looksLikePerson(string $token): bool
    {
        return mb_strlen($token, 'UTF-8') >= 3
            && preg_match('/^\p{Lu}[\p{Lu}\p{Ll}\-]+$/u', $token) === 1;
    }

    private function addPersonToken(string $token): void
    {
        $key = mb_strtolower($token, 'UTF-8');
        if ($key === '' || in_array($key, self::ALLOW, true) || isset($this->conversationPeople[$key])) {
            return;
        }

        $this->conversationPeople[$key] = $token;
        $this->conversationLane = null;
    }

    /**
     * H6918-2: добавить ник без @ в словарь беседы. Ник — буквы/цифры/
     * подчёркивание, ≥3 символов и хотя бы одна буква (телефоны и суммы уже
     * закрыты цифровыми слоями, числовой «ник» маскировать нельзя).
     */
    private function rememberNick(string $value): void
    {
        $value = ltrim(trim($value), '@');
        if ($value === '' || mb_strlen($value, 'UTF-8') < 3) {
            return;
        }
        if (preg_match('/^[\p{L}\p{N}_]+$/u', $value) !== 1 || preg_match('/\p{L}/u', $value) !== 1) {
            return;
        }

        $key = mb_strtolower($value, 'UTF-8');
        if (in_array($key, self::ALLOW, true) || isset($this->conversationNicks[$key])) {
            return;
        }

        $this->conversationNicks[$key] = $value;
        $this->conversationLane = null;
    }

    /** Сброс кэша identities (для долгоживущих воркеров после массовых правок users). */
    public function refresh(): void
    {
        $this->identities = null;
        $this->patterns = null;
    }

    private function ensureIdentities(): void
    {
        if ($this->patterns !== null) {
            return;
        }

        // Пары, не карта: PHP интитит числовые строковые ключи ('100500' → 100500),
        // и значение-личность в ключе ломает preg_quote/string-типы.
        $seen = [];
        $pairs = [];
        foreach (User::query()->select(['name', 'email', 'phone', 'telegram_username', 'vk_id'])->get() as $user) {
            foreach (['name', 'email', 'phone', 'telegram_username', 'vk_id'] as $field) {
                $value = trim((string) ($user->{$field} ?? ''));
                if (mb_strlen($value) >= 4 && ! in_array($value, self::ALLOW, true) && ! isset($seen[$value])) {
                    $seen[$value] = true;
                    $pairs[] = [$value, $field];
                }
            }
        }

        usort($pairs, fn (array $a, array $b): int => mb_strlen($b[0]) <=> mb_strlen($a[0]));
        $this->identities = array_column($pairs, 1, 0);
        $this->patterns = array_map(
            fn (array $p): array => $this->compileIdentity($p[0], $p[1]),
            $pairs,
        );
    }

    /**
     * Значение → [поле, паттерн, канонический ключ тега]. Телефонно-подобные
     * значения (только цифры и разделители) компилируются цифро-гибко (H6144):
     * форматированные написания — та же личность, ключ тега — нормализованные
     * цифры с префиксом 7, поэтому '+79161234567', '+7 (916) 123-45-67' и
     * '8-916-123-45-67' получают ОДИН тег-псевдоним на вызов.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function compileIdentity(string $value, string $field): array
    {
        $digits = preg_replace('/\D+/u', '', $value) ?? '';

        if ($digits !== '' && strlen($digits) >= 4 && preg_match(self::PHONE_LIKE, $value) === 1) {
            if (strlen($digits) === 11 && in_array($digits[0], ['7', '8'], true)) {
                $canonical = '7'.substr($digits, 1);
                $body = '';
                foreach (str_split(substr($canonical, 1)) as $digit) {
                    $body .= self::PHONE_SEP.'*'.$digit;
                }

                return [$field, '/(?<!\d)(?:\+7|8)'.$body.'(?!\d)/u', $canonical];
            }

            $body = '';
            foreach (str_split($digits) as $i => $digit) {
                $body .= ($i > 0 ? self::PHONE_SEP.'*' : '').$digit;
            }

            return [$field, '/(?<!\d)'.$body.'(?!\d)/u', $digits];
        }

        return [$field, '/(?<!\w)'.preg_quote($value, '/').'(?!\w)/u', $value];
    }

    /**
     * @param  string  $kind  вид тега (поле users, «email-shaped»/«phone-shaped», «handle», «person»)
     * @param  string  $value  канонический ключ дедупликации тегов внутри области
     * @param  string|null  $display  что вернуть в unmask(): для газетира ключ
     *                                регистронезависим, а восстанавливать надо исходное
     *                                написание («Мария», не «мария»)
     */
    private function tag(string $kind, string $value, ?string $display = null): string
    {
        $key = $kind.'|'.$value;
        $this->tags[$key] ??= count($this->tags) + 1;
        $number = $this->tags[$key];
        $this->values[$number] = $display ?? $value;

        return sprintf('<PII:%s:%d>', $kind, $number);
    }
}
