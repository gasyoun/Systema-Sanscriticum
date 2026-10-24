<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\SupportAnswerSuggestion;

/**
 * Pure regex categorizer shared by the suggester and the cabinet bot.
 *
 * Extracted from SupportAnswerSuggester (0YW): CuratorAi needs the category
 * without pulling the suggester's dependency graph, which is circular
 * (Suggester -> SupportLlmDraftComposer -> CuratorAi). No state, no I/O.
 */
final class SupportQuestionCategorizer
{
    /**
     * Дешёвые regex-правила. Список пар, не assoc: одна категория может иметь
     * несколько рук с разным приоритетом (типичные фразы ORS-FAQ 04/05/06).
     *
     * Порядок:
     * 0. Узкий D «ссылка на оплату» РАНЬШЕ ВСЕГО. Инцидент 19-09-2026:
     *    студентка просила «ссылку для оплаты», а вежливая оговорка «иногда
     *    буду… смотреть в записи» matчилась первой рукой B — и бот ответил на
     *    просьбу об оплате ссылкой на запись урока. Платёжное намерение рядом
     *    со «ссылкой» сильнее любой вежливой оговорки про записи/занятия.
     * 1. B (запись) раньше A, чтобы «ссылка на запись» не ушла в Zoom.
     * 2. Узкий C «ссылка на расписание» раньше A, иначе `ссылк` крадёт тему 06.
     * 3. A Zoom, затем широкий C.
     * 4. D/E/F после A/B/C.
     *
     * @var list<array{0: string, 1: string}>
     */
    public const RULES = [
        // H3394: деньги рядом со «ссылкой» — это D («ссылку на оплату не
        // нашла», «сколько стоит курс и где ссылка»), а не Zoom. Инцидент
        // 19-09-2026 поднял руку на первое место: раньше она стояла третьей и
        // проигрывала руке B по слову «записи» из оговорки.
        [SupportAnswerSuggestion::CATEGORY_PAYMENT, '/(?:ссылк|линк)[^\n]{0,48}(?:оплат|плат|тариф|цен|стои)|(?:оплат|тариф|цен|стои)[^\n]{0,48}(?:ссылк|линк)/iu'],
        [SupportAnswerSuggestion::CATEGORY_RECORDING, '/запис|видеозап|пересмотр|переслуш|тайм.?код|rutube|youtube|пропуст[а-яё]{0,8}.{0,40}(?:занят|урок|лекц)|(?:занят|урок|лекц).{0,40}пропуст/iu'],
        [SupportAnswerSuggestion::CATEGORY_SCHEDULE, '/ссылк[^\n]{0,48}расписан|расписан[^\n]{0,48}ссылк/iu'],
        [SupportAnswerSuggestion::CATEGORY_ZOOM, '/зум|zoom|подключ|ссылк|линк|\bjoin\b|как\s+(?:мне\s+)?(?:войти|зайти|попасть)|войти\s+в\s+(?:занятие|урок|встреч)/iu'],
        [SupportAnswerSuggestion::CATEGORY_SCHEDULE, '/расписан|когда\s+(?:занятие|урок|начн|следующ|будет|стартует|пара)|во\s?сколько|время\s+(?:занят|урок)|перенос|перенес|в\s+какой\s+день|график\s+занят|в\s+какое\s+время/iu'],
        // H3394: «сколько будет стоить», любое упоминание оплаты, «по частям».
        [SupportAnswerSuggestion::CATEGORY_PAYMENT, '/сколько\s+стоит|сколько[^\n]{0,24}стои|стоимост|\bцен[аеуы]\b|тариф|рассрочк|предоплат|доплат|скидк|промокод|по\s+частям|\bоплат|сколько\s+(?:мне\s+)?(?:платить|заплатить)|как\s+оплат|можно\s+ли\s+оплат/iu'],
        // H3394: «паролю/пароля», голое «кабинет» («Кабинет не открывается»).
        [SupportAnswerSuggestion::CATEGORY_ACCESS, '/нет\s+доступа|не\s+могу\s+(?:попасть|войти|зайти|открыт)\s+[^\n]{0,24}(?:кабинет|занят|урок)|\bкабинет|личн(?:ый|ым|ого)\s+кабинет|в\s+какой\s+(?:я\s+)?группе|моя\s+группа|какая\s+у\s+меня\s+группа|логин|парол/iu'],
        // H3394: «домашку/ДЗ», «куда прикреплять».
        [SupportAnswerSuggestion::CATEGORY_MATERIALS, '/материал|методичк|конспект|презентац|домашн|домашк|\bдз\b|задани|сертификат|диплом|удостоверен|раздаточ|прикрепл/iu'],
    ];

    public static function categorize(string $text): ?string
    {
        if (trim($text) === '') {
            return null;
        }

        foreach (self::RULES as [$category, $regex]) {
            if (preg_match($regex, $text)) {
                return $category;
            }
        }

        return null;
    }
}
