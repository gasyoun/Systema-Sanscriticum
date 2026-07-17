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
    // Дешёвые regex-правила категоризации входящего вопроса. Порядок важен:
    // «запись» проверяем раньше «ссылки», чтобы «ссылка на запись» ушла в B, а не A.
    private const RULES = [
        SupportAnswerSuggestion::CATEGORY_RECORDING => '/запис|видеозап|пересмотр|переслуш|тайм.?код|rutube|youtube/iu',
        SupportAnswerSuggestion::CATEGORY_ZOOM => '/зум|zoom|подключ|ссылк|линк|\bjoin\b|как\s+(?:мне\s+)?(?:войти|зайти|попасть)|войти\s+в\s+(?:занятие|урок|встреч)/iu',
        SupportAnswerSuggestion::CATEGORY_SCHEDULE => '/расписан|когда\s+(?:занятие|урок|начн|следующ|будет|стартует|пара)|во\s?сколько|время\s+(?:занят|урок)|перенос|перенес|в\s+какой\s+день|график\s+занят/iu',
        // v2 (S5): LLM-черновики. Порядок — ПОСЛЕ A/B/C, чтобы «ссылка на запись»
        // осталась за B, а не ушла в D/E по слову «оплатить/доступ».
        SupportAnswerSuggestion::CATEGORY_PAYMENT => '/сколько\s+стоит|стоимост|\bцен[аеуы]\b|тариф|рассрочк|предоплат|доплат|скидк|промокод|сколько\s+(?:мне\s+)?(?:платить|заплатить)|как\s+оплат|можно\s+ли\s+оплат/iu',
        SupportAnswerSuggestion::CATEGORY_ACCESS => '/нет\s+доступа|не\s+могу\s+(?:попасть|войти|зайти)\s+в\s+(?:личный\s+)?кабинет|личн(?:ый|ом)\s+кабинет|в\s+какой\s+(?:я\s+)?группе|моя\s+группа|какая\s+у\s+меня\s+группа|логин|пароль/iu',
        SupportAnswerSuggestion::CATEGORY_MATERIALS => '/материал|методичк|конспект|презентац|домашн|\bдз\b|задани|сертификат|диплом|удостоверен|раздаточ/iu',
    ];

    public function __construct(
        private readonly SupportAnswerFactResolver $facts,
        private readonly SupportLlmDraftComposer $llm,
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

        foreach (self::RULES as $category => $regex) {
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
            ? $this->resolveGuest($category, $sourceType)
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

        // A/B/C — строковый шаблон из фактов (без LLM). D/E/F (S5) — LLM
        // формулирует по фактам LMS, за флагом support_ai_assist + дневным cap.
        return in_array($category, SupportAnswerSuggestion::LLM_CATEGORIES, true)
            ? $this->llm->compose($category, $user, $text, $sourceType)
            : $this->facts->resolve($category, $user);
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
    private function resolveGuest(string $category, string $sourceType): ?array
    {
        if ($category !== SupportAnswerSuggestion::CATEGORY_PAYMENT || $sourceType !== SupportAnswerSuggestion::SOURCE_CHAT_MESSAGE) {
            return null;
        }

        return $this->facts->resolvePublicPricing();
    }
}
