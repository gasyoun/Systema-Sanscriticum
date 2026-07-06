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
 */
class SupportAnswerSuggester
{
    // Дешёвые regex-правила категоризации входящего вопроса. Порядок важен:
    // «запись» проверяем раньше «ссылки», чтобы «ссылка на запись» ушла в B, а не A.
    private const RULES = [
        SupportAnswerSuggestion::CATEGORY_RECORDING => '/запис|видеозап|пересмотр|переслуш|тайм.?код|rutube|youtube/iu',
        SupportAnswerSuggestion::CATEGORY_ZOOM => '/зум|zoom|подключ|ссылк|линк|\bjoin\b|как\s+(?:мне\s+)?(?:войти|зайти|попасть)|войти\s+в\s+(?:занятие|урок|встреч)/iu',
        SupportAnswerSuggestion::CATEGORY_SCHEDULE => '/расписан|когда\s+(?:занятие|урок|начн|следующ|будет|стартует|пара)|во\s?сколько|время\s+(?:занят|урок)|перенос|перенес|в\s+какой\s+день|график\s+занят/iu',
    ];

    public function __construct(private readonly SupportAnswerFactResolver $facts) {}

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
        if ($userId === null || trim($text) === '') {
            return false;
        }

        $category = $this->categorize($text);
        if ($category === null) {
            return false;
        }

        if (SupportAnswerSuggestion::query()->where('source_type', $sourceType)->where('source_id', $sourceId)->exists()) {
            return false;
        }

        $user = User::find($userId);
        if ($user === null) {
            return false;
        }

        $resolved = $this->facts->resolve($category, $user);
        if ($resolved === null) {
            // Категория опознана, но фактов в LMS нет (нет ближайшего занятия /
            // ссылки / записи) — пустой черновик куратору не показываем.
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
}
