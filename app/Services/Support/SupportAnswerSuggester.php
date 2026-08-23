<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\ChatMessage;
use App\Models\MarketingSetting;
use App\Models\SupportAnswerDetectionCursor;
use App\Models\SupportAnswerSuggestion;
use App\Models\TelegramSupportMessage;
use App\Models\User;

/**
 * Сканер FAQ-суггестера (H247, тикет S3): по обоим сторам (веб-чат ChatMessage +
 * импортированный TG-support) с хай-вотер-марк курсором находит фактологические
 * вопросы категорий A (Zoom/ссылка), B (записи), C (расписание) дешёвым
 * regex-префильтром — НИКАКОГО LLM в v1 — и для каждого собирает готовый черновик
 * ответа из данных LMS (SupportAnswerFactResolver). Сам НИЧЕГО не отправляет: только
 * заводит pending SupportAnswerSuggestion и пишет событие `answer_suggested`.
 * Точная копия механики ReminderRequestDetector (H187).
 *
 * Гостевые веб-треды (H1198, Jivo-паритет S3): `ChatMessage.user_id` = NULL для
 * анонимного посетителя (PublicChatController::store). У гостя нет `users`-записи
 * и, следовательно, нет группы/зачисления — категории A/B/C/E/F завязаны именно
 * на это и гостю в принципе недоступны. Единственная категория, для которой есть
 * осмысленные ФАКТЫ без аккаунта, — D (публичные тарифы курсов, без
 * персонализации): {@see SupportAnswerFactResolver::resolvePublicPricing()}.
 */
class SupportAnswerSuggester
{
    /**
     * Дешёвые regex-правила. Список пар, не assoc: одна категория может иметь
     * несколько рук с разным приоритетом (типичные фразы ORS-FAQ 04/05/06).
     *
     * Порядок:
     * 1. B (запись) раньше A, чтобы «ссылка на запись» не ушла в Zoom.
     * 2. Узкий C «ссылка на расписание» раньше A, иначе `ссылк` крадёт тему 06.
     * 3. A Zoom, затем широкий C.
     * 4. D/E/F после A/B/C.
     *
     * @var list<array{0: string, 1: string}>
     */
    private const RULES = [
        [SupportAnswerSuggestion::CATEGORY_RECORDING, '/запис|видеозап|пересмотр|переслуш|тайм.?код|rutube|youtube|пропуст[а-яё]{0,8}.{0,40}(?:занят|урок|лекц)|(?:занят|урок|лекц).{0,40}пропуст/iu'],
        [SupportAnswerSuggestion::CATEGORY_SCHEDULE, '/ссылк[^\n]{0,48}расписан|расписан[^\n]{0,48}ссылк/iu'],
        // H3394: деньги рядом со «ссылкой» — это D («ссылку на оплату не
        // нашла», «сколько стоит курс и где ссылка»), а не Zoom. Узкая рука
        // СТРОГО до широкого A-«ссылк», иначе A крадёт платёжные темы.
        [SupportAnswerSuggestion::CATEGORY_PAYMENT, '/(?:ссылк|линк)[^\n]{0,48}(?:оплат|плат|тариф|цен|стои)|(?:оплат|тариф|цен|стои)[^\n]{0,48}(?:ссылк|линк)/iu'],
        [SupportAnswerSuggestion::CATEGORY_ZOOM, '/зум|zoom|подключ|ссылк|линк|\bjoin\b|как\s+(?:мне\s+)?(?:войти|зайти|попасть)|войти\s+в\s+(?:занятие|урок|встреч)/iu'],
        [SupportAnswerSuggestion::CATEGORY_SCHEDULE, '/расписан|когда\s+(?:занятие|урок|начн|следующ|будет|стартует|пара)|во\s?сколько|время\s+(?:занят|урок)|перенос|перенес|в\s+какой\s+день|график\s+занят|в\s+какое\s+время/iu'],
        // H3394: «сколько будет стоить», любое упоминание оплаты, «по частям».
        [SupportAnswerSuggestion::CATEGORY_PAYMENT, '/сколько\s+стоит|сколько[^\n]{0,24}стои|стоимост|\bцен[аеуы]\b|тариф|рассрочк|предоплат|доплат|скидк|промокод|по\s+частям|\bоплат|сколько\s+(?:мне\s+)?(?:платить|заплатить)|как\s+оплат|можно\s+ли\s+оплат/iu'],
        // H3394: «паролю/пароля», голое «кабинет» («Кабинет не открывается»).
        [SupportAnswerSuggestion::CATEGORY_ACCESS, '/нет\s+доступа|не\s+могу\s+(?:попасть|войти|зайти|открыт)\s+[^\n]{0,24}(?:кабинет|занят|урок)|\bкабинет|личн(?:ый|ым|ого)\s+кабинет|в\s+какой\s+(?:я\s+)?группе|моя\s+группа|какая\s+у\s+меня\s+группа|логин|парол/iu'],
        // H3394: «домашку/ДЗ», «куда прикреплять».
        [SupportAnswerSuggestion::CATEGORY_MATERIALS, '/материал|методичк|конспект|презентац|домашн|домашк|\bдз\b|задани|сертификат|диплом|удостоверен|раздаточ|прикрепл/iu'],
    ];

    public function __construct(
        private readonly SupportAnswerFactResolver $facts,
        private readonly SupportLlmDraftComposer $llm,
        private readonly SupportTemplateDraftResolver $templates,
        private readonly Faq\Bm25FaqRetriever $faqRag,
        private readonly Faq\FaqRagDraftBuilder $faqDrafts,
    ) {}

    public function isEnabled(): bool
    {
        if (! config('features.support_answer_suggester', false)) {
            return false;
        }

        $settings = MarketingSetting::cached();

        return $settings !== null && (bool) $settings->support_answer_suggester_enabled;
    }

    /** Определить категорию вопроса (A/B/C) дешёвым regex или null. */
    public function categorize(string $text): ?string
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

    /** @return array{scanned: int, created: int} */
    public function run(): array
    {
        if (! $this->isEnabled()) {
            return ['scanned' => 0, 'created' => 0];
        }

        $scanned = 0;
        $created = $this->scanChatMessages($scanned) + $this->scanTelegramMessages($scanned);

        return ['scanned' => $scanned, 'created' => $created];
    }

    private function scanChatMessages(int &$scanned): int
    {
        $cursor = SupportAnswerDetectionCursor::lastId(SupportAnswerSuggestion::SOURCE_CHAT_MESSAGE);
        $created = 0;
        $maxId = $cursor;

        ChatMessage::query()
            ->where('role', 'user')
            ->where('id', '>', $cursor)
            ->orderBy('id')
            ->chunkById(200, function ($messages) use (&$scanned, &$created, &$maxId) {
                foreach ($messages as $message) {
                    $scanned++;
                    $maxId = max($maxId, $message->id);
                    // user_id = NULL — гостевой веб-тред (H1198); maybeSuggest сам
                    // сужает гостя до категории D (публичные тарифы, без фактов
                    // о личном зачислении).
                    if ($this->maybeSuggest(SupportAnswerSuggestion::SOURCE_CHAT_MESSAGE, $message->id, $message->user_id, (string) $message->text)) {
                        $created++;
                    }
                }
            });

        SupportAnswerDetectionCursor::advance(SupportAnswerSuggestion::SOURCE_CHAT_MESSAGE, $maxId);

        return $created;
    }

    private function scanTelegramMessages(int &$scanned): int
    {
        $cursor = SupportAnswerDetectionCursor::lastId(SupportAnswerSuggestion::SOURCE_TELEGRAM_SUPPORT_MESSAGE);
        $created = 0;
        $maxId = $cursor;

        TelegramSupportMessage::query()
            ->with(['chat', 'contact'])
            ->where('direction', 'incoming')
            ->where('id', '>', $cursor)
            ->orderBy('id')
            ->chunkById(200, function ($messages) use (&$scanned, &$created, &$maxId) {
                foreach ($messages as $message) {
                    $scanned++;
                    $maxId = max($maxId, $message->id);
                    $userId = $message->chat?->linked_user_id ?? $message->contact?->linked_user_id;
                    if ($userId === null) {
                        continue;
                    }
                    if ($this->maybeSuggest(SupportAnswerSuggestion::SOURCE_TELEGRAM_SUPPORT_MESSAGE, $message->id, $userId, (string) $message->text)) {
                        $created++;
                    }
                }
            });

        SupportAnswerDetectionCursor::advance(SupportAnswerSuggestion::SOURCE_TELEGRAM_SUPPORT_MESSAGE, $maxId);

        return $created;
    }

    private function maybeSuggest(string $sourceType, int $sourceId, ?int $userId, string $text): bool
    {
        if (trim($text) === '') {
            return false;
        }

        $category = $this->categorize($text);
        if ($category === null) {
            return false;
        }

        if (SupportAnswerSuggestion::query()->where('source_type', $sourceType)->where('source_id', $sourceId)->exists()) {
            return false;
        }

        $resolved = $userId === null
            ? $this->resolveGuest($category, $sourceType, $text)
            : $this->resolveForUser($category, $userId, $text, $sourceType);

        if ($resolved === null) {
            // Категория опознана, но черновика нет: для A/B/C — нет фактов в LMS;
            // для D/E/F — ещё и LLM выключен / достигнут дневной cap / нет ключа;
            // для гостя — категория не D, либо нет видимых курсов с тарифом.
            // Пустой черновик куратору не показываем.
            return false;
        }

        $suggestion = SupportAnswerSuggestion::create([
            'user_id' => $userId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'category' => $category,
            'detected_text' => $text,
            'draft_text' => $resolved['draft'],
            'facts' => $resolved['facts'],
            'confidence' => $resolved['confidence'],
            'status' => SupportAnswerSuggestion::STATUS_PENDING,
        ]);

        SupportAnswerEventLogger::log($suggestion, SupportAnswerEventLogger::EVENT_SUGGESTED);

        return true;
    }

    /**
     * @return array{draft: string, facts: array<string, mixed>, confidence: float}|null
     */
    private function resolveForUser(string $category, int $userId, string $text, string $sourceType): ?array
    {
        $user = User::find($userId);
        if ($user === null) {
            return null;
        }

        // H2448 FAQ RAG (flag OFF by default): retrieve top chunks BEFORE
        // template/LLM. Money/policy categories refuse without a hit above threshold.
        $ragHits = $this->faqRagHits($text);
        if ($this->faqRag->isEnabled()
            && in_array($category, $this->faqRag->moneyPolicyCategories(), true)
            && ! $this->faqRag->passesThreshold($ragHits)
        ) {
            return null;
        }

        if ($this->faqRag->isEnabled()
            && $ragHits !== []
            && $this->faqRag->passesThreshold($ragHits)
            && in_array($category, SupportAnswerSuggestion::LLM_CATEGORIES, true)
        ) {
            // Cited FAQ draft first for D/E/F when retrieval is strong enough.
            return $this->faqDrafts->fromHits($text, $ragHits, $category);
        }

        // A/B/C — строковый шаблон из фактов (без LLM). D/E/F (S5) — LLM
        // формулирует по фактам LMS, за флагом support_ai_assist + дневным cap.
        // S9 (H1838): перед LLM — привязанный шаблон MessageTemplate; есть
        // привязка → черновик из шаблона, LLM не вызывается вовсе.
        $resolved = in_array($category, SupportAnswerSuggestion::LLM_CATEGORIES, true)
            ? $this->templates->resolve($category, $user)
                ?? $this->llm->compose($category, $user, $text, $sourceType)
            : $this->facts->resolve($category, $user);

        if ($resolved === null) {
            return null;
        }

        return $this->faqRag->isEnabled()
            ? $this->faqDrafts->attachCitations($resolved, $ragHits)
            : $resolved;
    }

    /**
     * Гость (H1198): нет `users`-записи → нет группы/зачисления → категории
     * A/B/C/E/F недоступны в принципе (их факты все личные). D (публичные
     * тарифы) — единственная, для которой факт существует без аккаунта, и
     * только на веб-канале (Telegram-support гостей структурно не бывает —
     * scanTelegramMessages уже требует linked_user_id).
     *
     * @return array{draft: string, facts: array<string, mixed>, confidence: float}|null
     */
    private function resolveGuest(string $category, string $sourceType, string $text = ''): ?array
    {
        if ($category !== SupportAnswerSuggestion::CATEGORY_PAYMENT || $sourceType !== SupportAnswerSuggestion::SOURCE_CHAT_MESSAGE) {
            return null;
        }

        // H2448: guest payment questions may use FAQ citations when flag ON.
        $ragHits = $this->faqRagHits($text);
        if ($this->faqRag->isEnabled()
            && $ragHits !== []
            && $this->faqRag->passesThreshold($ragHits)
        ) {
            return $this->faqDrafts->fromHits($text, $ragHits, $category);
        }

        $resolved = $this->facts->resolvePublicPricing();
        if ($resolved === null) {
            return null;
        }

        return $this->faqRag->isEnabled()
            ? $this->faqDrafts->attachCitations($resolved, $ragHits)
            : $resolved;
    }

    /**
     * @return list<array{chunk_id: string, title: string, heading_path: list<string>, snippet: string, source: string, score: float}>
     */
    private function faqRagHits(string $text): array
    {
        if (! $this->faqRag->isEnabled() || trim($text) === '') {
            return [];
        }

        return $this->faqRag->retrieve($text);
    }
}
