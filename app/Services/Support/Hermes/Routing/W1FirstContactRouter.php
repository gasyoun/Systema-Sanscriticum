<?php

declare(strict_types=1);

namespace App\Services\Support\Hermes\Routing;

use App\Models\SupportAnswerSuggestion;
use App\Services\Support\SupportQuestionCategorizer;

/**
 * Общий классификатор первого контакта W1 (H6190, пакет hermes-24-01a10bca).
 *
 * Чистая логика без I/O: вход — текст обращения + машиночитаемый контекст,
 * выход — W1RoutingDecision. Никаких исходящих сообщений, никаких записей,
 * никаких LLM-вызовов; входящий текст считается ДАННЫМИ (риск №7 стратегии
 * 2026-10-04), поэтому injection-попытки уводятся к человеку без черновиков.
 *
 * Приоритет категорий (ровно контракт golden-фикстуры H6181 routing.json):
 *  1. forbidden — prompt injection / обход полномочий / запрещённые контуры
 *     (возврат, скидка, выдача доступа без основания) → human, no_llm_draft;
 *  2. escalation — триггеры принятого runbook docs/RUNBOOK_SUPPORT_ESCALATION.md
 *     (деньги / прогресс / конфликт / тех-сбой) → human, direct_escalation;
 *  3. happy — однозначное намерение одной роли ростера V2 → role:N;
 *  4. ambiguity — не опознано уверенное намерение → human, no_role_loop.
 *
 * Переиспользование, не параллельный механизм: намерения опознаются общим
 * SupportQuestionCategorizer (regex-контур suggester'а); новые рубрики здесь
 * только там, где общего контура ещё нет (конфликт, injection, онбординг).
 * Ключевые слова TechnicalIssueDetector НЕ переиспользуются сознательно: их
 * список («ссылка», «кабинет», «вход») заточен под открытие helpdesk-треда и
 * отправил бы happy-обращения («привяжите Telegram к кабинету») в
 * тех-эскалацию вместо роли — у триггера «Тех-сбой» свой узкий контур
 * из runbook docs/RUNBOOK_SUPPORT_ESCALATION.md.
 */
final class W1FirstContactRouter
{
    /**
     * Prompt injection / jailbreak / попытки обхода полномочий.
     *
     * @var list<string>
     */
    private const INJECTION_PATTERNS = [
        '/проигнорируй|игнорируй[^\n]{0,24}(?:инструкц|правил|запрос|промпт|контекст)/iu',
        '/ignore\s+(?:previous|prior|above|all|any|the)/iu',
        '/system\s*prompt|developer\s*mode|jailbreak/iu',
        '/раскрой[^\n]{0,20}(?:промпт|инструкц|системн)|выведи[^\n]{0,20}(?:промпт|инструкц)/iu',
        '/ты\s+теперь[^\n]{0,24}(?:друг|иной|без|игнор)|сбрось[^\n]{0,16}(?:инструкц|правил|огранич)/iu',
        '/сними[^\n]{0,16}(?:огранич|фильтр|запрет)|bypass|обойд[иь][^\n]{0,16}(?:огранич|запрет|правил)/iu',
    ];

    /**
     * Запрещённые контуры (манифест H6181: возврат/скидка/выдача доступа) и
     * обход человека: мутационные требования, которые ни одна роль-LLM не
     * исполняет и не черновит.
     *
     * @var list<string>
     */
    private const FORBIDDEN_DEMAND_PATTERNS = [
        '/(?:оформ|сдела|произвед|провед|верн|списа|спиш|отмен|перенес|перевед|пересчит|зачт|открой|выдай|дай|давай)[^\n]{0,48}(?:возврат|refund|скидк|промокод|перерасч|списан|долг|отсроч|рассрочк|доступ)/iu',
        '/(?:возврат|скидк|доступ)[^\n]{0,24}(?:сразу|немедленно|автоматически|без\s+(?:администратор|человек|одобрен|согласован))/iu',
        '/(?:администратор|человек)\s+не\s+нужен|без\s+(?:администратор|человек|одобрен|согласован|проверк)/iu',
        '/привяж\w{0,12}[^\n]{0,40}(?:чуж|жены|мужа|другого|подруги|брата|сестры)/iu',
        '/открой\w{0,12}[^\n]{0,32}(?:без\s+оплат|попозже|потом|честное\s+слово)/iu',
    ];

    /**
     * Конфликт (runbook H6133: жалоба на преподавателя/куратора, спор о
     * правилах, грубость).
     *
     * @var list<string>
     */
    private const CONFLICT_PATTERNS = [
        '/жалоб|жалу[ею]сь|груб|оскорб|хамств|безобраз|скандал|конфликт/iu',
        '/надзор|прокуратур|потребительск|мошенн|обман/iu',
        '/спор[^\n]{0,20}правил|правил[^\n]{0,20}спор|треб\w{0,12}[^\n]{0,20}(?:начальник|руководител|менеджер)/iu',
    ];

    /**
     * Тех-сбой (runbook H6133: сайт/кабинет недоступен, бот молчит, не
     * приходит ссылка/код входа).
     *
     * @var list<string>
     */
    private const TECHNICAL_PATTERNS = [
        '/тех.?сбой|перебой|недоступн|сломал/iu',
        '/(?:сайт|кабинет|страница)[^\n]{0,20}(?:не\s+(?:открыва|работа|грузит|загружа|доступен)|недоступн|упал)/iu',
        '/не\s+(?:работа|грузит|загружа)[^\n]{0,24}(?:сайт|кабинет|страница|видео|бот)/iu',
        '/бот[^\n]{0,16}(?:молчит|не\s+отвечает|не\s+работа)/iu',
        '/видео[^\n]{0,16}(?:не\s+(?:играет|ид[её]т|загру|работа))/iu',
        '/не\s+приходит[^\n]{0,24}(?:ссылк|код|письм|подтвержд|доступ)/iu',
        '/не\s+(?:могу\s+)?(?:зайти|войти|открыть\s+(?:сайт|кабинет))/iu',
    ];

    /**
     * Прогресс (runbook H6133: домашка не засчитана, нет доступа к уроку/записи,
     * сертификат не выдан).
     *
     * @var list<string>
     */
    private const PROGRESS_PATTERNS = [
        '/домашн|\bдз\b|не\s+засчит|не\s+зачли|сертификат/iu',
        '/нет\s+доступа|пропал\w{0,12}[^\n]{0,20}доступ|доступ[^\n]{0,20}(?:пропал|нет|не\s+(?:работа|открыва|даёт))/iu',
        '/не\s+(?:открыва|доступн)\w{0,12}[^\n]{0,24}(?:занят|урок|запис|материал|кабинет)/iu',
        '/не\s+(?:вижу|нахожу)[^\n]{0,24}(?:домаш|задани|материал|занят|отметк|зач[тё]т)/iu',
    ];

    /**
     * Денежный контур сверх общего SupportQuestionCategorizer (возврат,
     * списание, долг — его рубрика D/E этого не покрывает).
     *
     * @var list<string>
     */
    private const MONEY_PATTERNS = [
        '/\bвозврат\w*|refund|\bсписа\w*|\bспиш\w*|двойн\w{0,12}списа|\bдолг\w*|\bзадолжен\w*/iu',
        '/\bденьг\w*|денежн\w*|кэшбэк|верните\s+деньги/iu',
    ];

    /**
     * Состояние неизвестно (H6181 ambiguity-контракт: «никакого уверенного
     * угадывания»): проверочные просьбы и «не помню/не знаю» — человеку.
     *
     * @var list<string>
     */
    private const UNCERTAINTY_PATTERNS = [
        '/не\s+(?:помню|знаю|уверен|уверена|помню\s+точно)/iu',
        '/проверьте|уточните,?\s+(?:пожалуйста,?\s+)?(?:привязан|есть\s+ли|какой|какая|моя)|привязан\s+ли|есть\s+ли\s+(?:привязка|доступ|оплата|группа)/iu',
    ];

    /** Онбординг/привязка → роль 19 `onboarding-greeter`. */
    private const ONBOARDING_PATTERNS = [
        '/привяза|онбординг|чек.?лист/iu',
        '/(?:telegram|телеграм\w*)[^\n]{0,24}(?:кабинет|аккаунт|бот|привяз)/iu',
    ];

    /** Первый контакт/запись на курс → роль 13 `first-contact-247`. */
    private const FIRST_CONTACT_PATTERNS = [
        '/записаться|записался|записыва[ею]сь|хочу\s+учиться|хочу\s+на\s+курс/iu',
        '/с\s+чего\s+начать|как\s+начать\s+(?:обучение|заниматься|учиться)|какие\s+курсы/iu',
    ];

    /** FAQ-контуры сверх SupportQuestionCategorizer → роль 20 `faq-rag`. */
    private const FAQ_PATTERNS = [
        '/учебник|словар|глоссар|как\s+устроен|где\s+найти|какие\s+книг/iu',
    ];

    /**
     * @param  array<string, mixed>  $context  машиночитаемый контекст события
     *                                         (channel, author, sensitive, prompt_injection_suspected, …)
     */
    public function decide(string $message, array $context = []): W1RoutingDecision
    {
        $text = trim($message);

        // 1. Forbidden: injection и обход полномочий — раньше всего.
        if (($context['prompt_injection_suspected'] ?? false) === true
            || $this->matchesAny(self::INJECTION_PATTERNS, $text)) {
            return W1RoutingDecision::forbidden('injection_suspected', $this->isMoneyOrConflict($text));
        }

        if ($this->matchesAny(self::FORBIDDEN_DEMAND_PATTERNS, $text)) {
            return W1RoutingDecision::forbidden('forbidden_contour_demand', $this->isMoneyOrConflict($text));
        }

        // 2. Escalation: триггеры runbook H6133 → человек, прямая эскалация.
        if (($context['sensitive'] ?? false) === true) {
            return W1RoutingDecision::escalation('sensitive_context');
        }

        if ($this->isMoney($text)) {
            return W1RoutingDecision::escalation('runbook_money');
        }

        if ($this->matchesAny(self::CONFLICT_PATTERNS, $text)) {
            return W1RoutingDecision::escalation('runbook_conflict');
        }

        if ($this->matchesAny(self::PROGRESS_PATTERNS, $text)) {
            return W1RoutingDecision::escalation('runbook_progress');
        }

        if ($this->matchesAny(self::TECHNICAL_PATTERNS, $text)) {
            return W1RoutingDecision::escalation('runbook_technical');
        }

        // 3. Happy: однозначное намерение одной роли ростера V2. Состояние
        //    «неизвестно/проверьте» раньше ролей — без уверенного угадывания.
        if ($this->matchesAny(self::UNCERTAINTY_PATTERNS, $text)) {
            return W1RoutingDecision::ambiguity('state_unknown_no_guess');
        }

        if ($this->matchesAny(self::ONBOARDING_PATTERNS, $text)) {
            return W1RoutingDecision::toRole(19, 'intent_onboarding');
        }

        if ($this->matchesAny(self::FIRST_CONTACT_PATTERNS, $text)) {
            return W1RoutingDecision::toRole(13, 'intent_first_contact');
        }

        $category = SupportQuestionCategorizer::categorize($text);
        $roleByCategory = match ($category) {
            // Ссылки на занятия — домен schedule-keeper: один проход сразу в
            // роль 23, без delegate-петли роль 20 → роль 23 (fq-002 H6181).
            SupportAnswerSuggestion::CATEGORY_SCHEDULE, SupportAnswerSuggestion::CATEGORY_ZOOM => 23,
            SupportAnswerSuggestion::CATEGORY_RECORDING, SupportAnswerSuggestion::CATEGORY_MATERIALS => 20,
            SupportAnswerSuggestion::CATEGORY_ACCESS => 85,
            default => null,
        };

        if ($roleByCategory !== null) {
            return W1RoutingDecision::toRole($roleByCategory, 'intent_'.$category);
        }

        if ($this->matchesAny(self::FAQ_PATTERNS, $text)) {
            return W1RoutingDecision::toRole(20, 'intent_faq');
        }

        // 4. Ambiguity: без уверенного угадывания — человеку, без петель.
        return W1RoutingDecision::ambiguity('ambiguous_no_intent');
    }

    /** Деньги или конфликт — обязательная эскалация даже из forbidden-ветки. */
    private function isMoneyOrConflict(string $text): bool
    {
        return $this->isMoney($text) || $this->matchesAny(self::CONFLICT_PATTERNS, $text);
    }

    private function isMoney(string $text): bool
    {
        return SupportQuestionCategorizer::categorize($text) === SupportAnswerSuggestion::CATEGORY_PAYMENT
            || $this->matchesAny(self::MONEY_PATTERNS, $text);
    }

    /**
     * @param  list<string>  $patterns
     */
    private function matchesAny(array $patterns, string $text): bool
    {
        if ($text === '') {
            return false;
        }

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }
}
