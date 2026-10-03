<?php

namespace App\Services\SupportQuestions;

use App\Models\SupportTopicRule;

/**
 * Посообщенная классификация входящих вопросов поддержки (H5709).
 *
 * Отличие от SupportTopicClassifier: тот склеивает ВСЕ тексты субъекта за
 * день и назначает теме разговор-день — для подсчёта студенческих вопросов
 * это не годится (один день = один топик). Здесь единица классификации —
 * ОДНО сообщение, у него ровно один primary-топик (стабильный выбор при
 * нескольких попаданиях) и опциональные secondary-метки.
 *
 * Детерминированный keyword-классификатор: плоские подстроки (mb_stripos)
 * по нижнему регистру, БЕЗ регулярок и без staged pattern_hash-правил
 * (спека H5709: глобально их не включать). Существующие keyword-правила
 * SupportTopicRule переиспользуются там, где их категория ложится на
 * таксономию A–I (карта RULE_CATEGORY_MAP); unmapped категории игнорируются.
 *
 * Precision-first: попадание хотя бы в один топик даёт primary; сообщения
 * без попадания остаются unclassified (null) и считаются отдельно — покрытие
 * отчётно, а не вытягивается принудительно.
 */
class QuestionMessageClassifier
{
    public const VERSION = 'qw-2026-10-v1';

    public const CATEGORY_UNCLASSIFIED = 'unclassified';

    /** Стабильный порядок категорий: тай-брейк при равной специфичности. */
    public const CATEGORY_ORDER = ['D', 'G', 'A', 'B', 'C', 'E', 'F', 'I', 'H'];

    /** Человекочитаемые названия (отчёт/дашборд). */
    public const CATEGORY_TITLES = [
        'A' => 'Zoom / ссылка / подключение',
        'B' => 'Записи / видео / тайм-коды',
        'C' => 'Расписание / время / переносы',
        'D' => 'Цены / тарифы / оплата / возвраты',
        'E' => 'Кабинет / чат / доступ к группе',
        'F' => 'Материалы / ДЗ / сертификаты',
        'G' => 'Статус оплаты / долг',
        'H' => 'Состав / куратор / ростер',
        'I' => 'Выдача ссылок / доступов / добавление',
    ];

    /**
     * Категория => ключевые стемы. Подстрочный матч по mb_strtolower; стемы
     * подобраны из июльской таксономии (Uprava telegram-zabota-export):
     * специфичные многословные фразы впереди однословных, голых «ссылк»/«дост
     * нет» — только внутри устойчивых сочетаний.
     */
    private const CATEGORY_KEYWORDS = [
        'A' => [
            'zoom', 'зум', 'вебинар', 'конференц', 'подключиться к', 'подключится',
            'зайти по ссылк', 'войти по ссылк', 'не могу зайти', 'не могу подключ',
            'не пускает', 'ссылка на занятие', 'ссылку на занятие', 'ссылка на урок',
            'ссылку на урок', 'ссылка на звонок', 'ссылку на звонок', 'ссылка на вебинар',
            'ссылку на вебинар', 'meeting', 'сколько идёт занятие', 'не впускает',
        ],
        'B' => [
            'запис', 'видео', 'таймкод', 'тайм-код', 'тайм код', 'пересмотреть',
            'ролик', 'эфир', 'трансляц',
        ],
        'C' => [
            'расписан', 'во сколько', 'когда занятие', 'когда занятия', 'когда урок',
            'когда будет занятие', 'когда старт', 'перенесл', 'перенос', 'перенесите',
            'время занят', 'в какое время', 'график', 'в котором часу', 'отмен',
        ],
        'D' => [
            'цен', 'стоимост', 'сколько стоит', 'тариф', 'оплат', 'картой', 'перевод',
            'реквизит', 'чек на оплат', 'возврат', 'вернуть деньг', 'скидк', 'рассрочк',
            'счёт на оплат', 'счет на оплат', 'инвойс', 'продлить оплат', 'продление',
            'как заплатить', 'как оплатить',
        ],
        'E' => [
            'кабинет', 'личный кабинет', 'логин', 'пароль', 'не заходит', 'не заходит в кабинет',
            'войти в кабинет', 'доступ к кабинету', 'не добавлен в группу',
            'не добавлена в группу', 'не добавили в группу', 'не вижу группу',
            'аккаунт не находит', 'не находит аккаунт', 'не могу войти',
            'не вижу чат группы', 'нет чата группы', 'чат группы не открывается',
            'не вижу чат', 'нет доступа к чату',
        ],
        'F' => [
            'материал', 'дз', 'домашн', 'задани', 'сертификат', 'диплом', 'конспект',
            'презентац', 'файл с уроком', 'pdf', 'методичк', 'учебник',
        ],
        'G' => [
            'не списал', 'списал', 'платёж прошёл', 'платеж прошел', 'оплачено',
            'оплата прошла', 'долг', 'должен', 'осталось занятий', 'сколько занятий',
            'подписка истека', 'абонемент', 'баланс', 'занятий оплачено', 'прошла ли оплата',
        ],
        'H' => [
            'куратор', 'кто ведёт', 'кто ведет', 'состав группы', 'переведите в группу',
            'перевести в группу', 'переводите в другую группу', 'преподаватель смен',
            'кто в группе', 'кто преподава', 'ротер',
        ],
        'I' => [
            'выдайте ссылку', 'выдать ссылку', 'выдайте доступ', 'выдать доступ',
            'добавьте в чат', 'добавить в чат', 'добавьте в группу', 'добавить в группу',
            'открыть доступ', 'откройте доступ', 'дайте доступ', 'дайте доступ к',
            'подключите к', 'подключить к группе', 'внести в список', 'добавить в список',
            'добавьте меня в чат', 'добавить меня в чат', 'добавьте меня в группу',
            'подключите меня к', 'подключить меня к', 'внесите меня в список',
            'добавьте в список', 'внесите в список',
        ],
    ];

    /**
     * Категория существующего SupportTopicRule => буква таксономии A–I.
     * Только validated-маппинг; всё вне карты в отчёте не участвует.
     */
    private const RULE_CATEGORY_MAP = [
        'zoom_link' => 'A',
        'recording_access' => 'B',
        'schedule' => 'C',
        'schedule_query' => 'C',
        'b1_time' => 'C',
        'b6_start_date' => 'C',
        'payment' => 'D',
        'payment_billing' => 'D',
        'b2_price' => 'D',
        'price_query' => 'D',
        'b8_payment_mechanics' => 'D',
        'refund' => 'D',
        'buy_signal' => 'D',
        'access' => 'E',
        'access_login' => 'E',
        'tech_issue' => 'E',
        'technical' => 'E',
        'materials' => 'F',
        'materials_content' => 'F',
        'certificate' => 'F',
        'homework_progress' => 'F',
        'membership_club' => 'H',
        'urgent' => null, // маркер срочности, не топик
    ];

    /** Вопросность без '?': вопросительные слова + просьбы + сигналы проблемы. */
    private const QUESTION_WORDS = [
        'как', 'где', 'когда', 'сколько', 'почему', 'зачем', 'какой', 'какая',
        'какие', 'какую', 'каким', 'куда', 'откуда', 'можно ли', 'можно ',
        'подскажите', 'скажите', 'уточните', 'скажите пожалуйста', 'не знаю',
    ];

    private const REQUEST_WORDS = [
        'прошу', 'отправьте', 'пришлите', 'дайте', 'сделайте', 'помогите',
        'проверьте', 'посмотрите', 'напишите', 'подключите', 'добавьте',
        'выдайте', 'откройте', 'внесите', 'перенесите', 'продлите',
    ];

    private const PROBLEM_WORDS = [
        'не могу', 'не получается', 'не работает', 'не удалось', 'не выходит',
        'проблема', 'ошибка', 'не вижу', 'не видно', 'не пришло', 'не пришёл',
        'не пришла', 'сломал', 'пропал', 'исчез', 'не открывается', 'не грузится',
    ];

    /** @var array<string, list<string>>|null keywords по категории (встроенные + правила БД) */
    private ?array $keywordsByCategory = null;

    /**
     * Полный вердикт по одному тексту.
     *
     * @return array{
     *     is_question: bool,
     *     primary_category: string|null,
     *     secondary_categories: list<string>,
     *     signals: list<string>,
     *     hits: list<string>
     * }
     */
    public function classifyMessage(string $text): array
    {
        $haystack = mb_strtolower(trim($text));

        $signals = $this->detectSignals($haystack);

        $matched = $this->matchCategories($haystack);
        $primary = $matched[0]['category'] ?? null;
        $hits = array_map(
            static fn (array $m): string => $m['category'].':'.$m['keyword'],
            $matched
        );

        // Топик-попадание само по себе запрос: «зум 123» без вопросительных
        // слов всё равно вопрос о подключении. Покрытие честное, т.к. топики
        // — специфичные фразы, не одиночные общие слова.
        $isQuestion = $signals !== [] || $primary !== null;

        $secondary = [];
        foreach (array_slice($matched, 1) as $m) {
            // Одна категория может попасть несколькими ключами — secondary
            // не повторяет ни primary, ни сам себя.
            if ($m['category'] !== $primary && ! in_array($m['category'], $secondary, true)) {
                $secondary[] = $m['category'];
            }
            if (count($secondary) >= 2) {
                break;
            }
        }

        return [
            'is_question' => $isQuestion,
            'primary_category' => $primary,
            'secondary_categories' => $secondary,
            'signals' => $signals,
            'hits' => $hits,
        ];
    }

    /**
     * @return list<string>
     */
    private function detectSignals(string $haystack): array
    {
        $signals = [];
        if (mb_strpos($haystack, '?') !== false || mb_strpos($haystack, '？') !== false) {
            $signals[] = 'question_mark';
        }
        foreach (self::QUESTION_WORDS as $word) {
            if (mb_strpos($haystack, $word) !== false) {
                $signals[] = 'question_word:'.$word;
            }
        }
        foreach (self::REQUEST_WORDS as $word) {
            if (mb_strpos($haystack, $word) !== false) {
                $signals[] = 'request_word:'.$word;
            }
        }
        foreach (self::PROBLEM_WORDS as $word) {
            if (mb_strpos($haystack, $word) !== false) {
                $signals[] = 'problem_word:'.$word;
            }
        }

        return $signals;
    }

    /**
     * Все попадания, отсортированные: выше специфичность (слов в фразе),
     * затем фиксированный порядок категорий — стабильный primary.
     *
     * @return list<array{category: string, keyword: string, specificity: int}>
     */
    private function matchCategories(string $haystack): array
    {
        $matched = [];
        foreach ($this->keywords() as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if ($keyword !== '' && mb_strpos($haystack, $keyword) !== false) {
                    $matched[] = [
                        'category' => $category,
                        'keyword' => $keyword,
                        'specificity' => count(preg_split('/\s+/u', trim($keyword)) ?: []),
                    ];
                }
            }
        }

        usort($matched, static function (array $a, array $b): int {
            if ($a['specificity'] !== $b['specificity']) {
                return $b['specificity'] <=> $a['specificity'];
            }

            $pa = array_search($a['category'], self::CATEGORY_ORDER, true);
            $pb = array_search($b['category'], self::CATEGORY_ORDER, true);

            return $pa <=> $pb;
        });

        return $matched;
    }

    /**
     * Ключевые слова категории: встроенные + переиспользованные правила БД
     * (только is_enabled, pattern_hash IS NULL — staged не включаем никогда).
     *
     * @return array<string, list<string>>
     */
    private function keywords(): array
    {
        if ($this->keywordsByCategory !== null) {
            return $this->keywordsByCategory;
        }

        $byCategory = self::CATEGORY_KEYWORDS;

        SupportTopicRule::query()
            ->where('is_enabled', true)
            ->whereNull('pattern_hash')
            ->get()
            ->each(function (SupportTopicRule $rule) use (&$byCategory): void {
                $target = self::RULE_CATEGORY_MAP[$rule->category] ?? null;
                if ($target === null) {
                    return;
                }
                foreach ($this->keywordsOf($rule) as $keyword) {
                    $byCategory[$target][] = $keyword;
                }
            });

        // Дедуп внутри категории — правило и встроенный стем могут совпасть.
        foreach ($byCategory as $category => $keywords) {
            $byCategory[$category] = array_values(array_unique($keywords));
        }

        return $this->keywordsByCategory = $byCategory;
    }

    /**
     * @return array<int, string>
     */
    private function keywordsOf(SupportTopicRule $rule): array
    {
        $keywords = $rule->keywords;

        if (is_string($keywords)) {
            $keywords = preg_split('/[,\n]+/', $keywords) ?: [];
        }

        return array_values(array_filter(array_map(
            static fn ($keyword): string => mb_strtolower(trim((string) $keyword)),
            is_array($keywords) ? $keywords : [],
        ), static fn (string $keyword): bool => $keyword !== ''));
    }
}
