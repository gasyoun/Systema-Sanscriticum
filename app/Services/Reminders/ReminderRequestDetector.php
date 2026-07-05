<?php

declare(strict_types=1);

namespace App\Services\Reminders;

use App\Models\ChatMessage;
use App\Models\MarketingSetting;
use App\Models\ReminderDetectionCursor;
use App\Models\ReminderSuggestion;
use App\Models\TelegramSupportMessage;
use App\Services\Bot\CuratorAi;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Гибридный детектор просьб «напомните мне» в переписке (веб-чат + импортированный
 * TG-support). Дешёвый regex-префильтр отсекает большинство сообщений; только
 * прошедшие его уходят в LLM (CuratorAi) на структурное извлечение даты/причины.
 * Сам детектор НИЧЕГО не отправляет — только создаёт ReminderSuggestion (pending)
 * для ручного подтверждения куратором. См. H187.
 */
class ReminderRequestDetector
{
    private const REGEX = '/напомн|ремайнд|remind|ping\s*me|после\s+\d|через\s+(?:недел|день|дня|дней|месяц|мин|час)|когда\s+(?:я\s+)?верн|when\s+i.?m\s+back|напиши(?:те)?\s+мне\s+(?:после|через|когда)/iu';

    public function __construct(private readonly CuratorAi $ai) {}

    public function isEnabled(): bool
    {
        $settings = MarketingSetting::cached();

        return $settings === null ? true : (bool) $settings->reminder_detection_enabled;
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
        $cursor = ReminderDetectionCursor::lastId(ReminderSuggestion::SOURCE_CHAT_MESSAGE);
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
                    if ($this->maybeSuggest(ReminderSuggestion::SOURCE_CHAT_MESSAGE, $message->id, $message->user_id, (string) $message->text)) {
                        $created++;
                    }
                }
            });

        ReminderDetectionCursor::advance(ReminderSuggestion::SOURCE_CHAT_MESSAGE, $maxId);

        return $created;
    }

    private function scanTelegramMessages(int &$scanned): int
    {
        $cursor = ReminderDetectionCursor::lastId(ReminderSuggestion::SOURCE_TELEGRAM_SUPPORT_MESSAGE);
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
                    if ($this->maybeSuggest(ReminderSuggestion::SOURCE_TELEGRAM_SUPPORT_MESSAGE, $message->id, $userId, (string) $message->text)) {
                        $created++;
                    }
                }
            });

        ReminderDetectionCursor::advance(ReminderSuggestion::SOURCE_TELEGRAM_SUPPORT_MESSAGE, $maxId);

        return $created;
    }

    private function maybeSuggest(string $sourceType, int $sourceId, ?int $userId, string $text): bool
    {
        if ($userId === null || trim($text) === '') {
            return false;
        }

        if (! preg_match(self::REGEX, $text)) {
            return false;
        }

        if (ReminderSuggestion::query()->where('source_type', $sourceType)->where('source_id', $sourceId)->exists()) {
            return false;
        }

        $extraction = $this->extract($text);
        if ($extraction === null || ! ($extraction['is_reminder_request'] ?? false)) {
            return false;
        }

        $confidence = (float) ($extraction['confidence'] ?? 0);
        if ($confidence < (float) config('reminders.confidence_threshold', 0.5)) {
            return false;
        }

        $suggestedDate = null;
        if (! empty($extraction['date'])) {
            try {
                $suggestedDate = Carbon::parse($extraction['date']);
            } catch (\Throwable) {
                $suggestedDate = null;
            }
        }

        ReminderSuggestion::create([
            'user_id' => $userId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'detected_text' => $extraction['reason'] ?? $text,
            'suggested_date' => $suggestedDate,
            'confidence' => $confidence,
            'status' => ReminderSuggestion::STATUS_PENDING,
        ]);

        return true;
    }

    /**
     * @return array{is_reminder_request?: bool, date?: ?string, reason?: ?string, confidence?: float}|null
     */
    private function extract(string $text): ?array
    {
        $prompt = 'Ты классифицируешь сообщение студента школы санскрита. Определи, просит ли '
            .'студент напомнить ему о чём-либо позже (например «напомните после 12-го», «напиши мне '
            .'через неделю», «когда вернусь из поездки»). Верни СТРОГО JSON без пояснений и без '
            .'markdown-обрамления, формат: {"is_reminder_request": true|false, "date": "YYYY-MM-DD"'
            .' или null, "reason": "краткая суть просьбы на русском" или null, "confidence": 0..1}. '
            .'Текущая дата: '.now()->toDateString().'.';

        $answer = $this->ai->chat([
            ['role' => 'system', 'content' => $prompt],
            ['role' => 'user', 'content' => $text],
        ]);

        if ($answer === null) {
            return null;
        }

        $json = trim((string) preg_replace('/^```(?:json)?|```$/m', '', trim($answer)));
        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            Log::warning('ReminderRequestDetector: не удалось распарсить JSON от LLM', ['raw' => $answer]);

            return null;
        }

        return $decoded;
    }
}
