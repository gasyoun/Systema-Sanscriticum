<?php

declare(strict_types=1);

namespace App\Services\Promises;

use App\Models\ChatMessage;
use App\Models\MarketingSetting;
use App\Models\PaymentPromiseSuggestion;
use App\Models\PromiseSuggestionDetectionCursor;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Services\Bot\CuratorAi;
use App\Services\StudentDebtsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Hybrid detector for payment-deferral language in web chat + imported TG-support.
 * Cheap regex prefilter → LLM JSON extract → pending PaymentPromiseSuggestion only.
 * Never creates PaymentPromise or conditional access (H2156).
 */
class PaymentPromiseDeferralDetector
{
    private const REGEX = '/оплач|отсроч|перенес.*оплат|до\s+\d{1,2}|к\s+\d{1,2}|после\s+зарплат|pay\s+by|will\s+pay|defer|оплачу|перенес(ти|у)?\s+(оплат|платеж)/iu';

    public function __construct(
        private readonly CuratorAi $ai,
        private readonly StudentDebtsService $debts,
    ) {}

    public function isEnabled(): bool
    {
        $settings = MarketingSetting::cached();

        // Default OFF when no MarketingSetting row (safer than reminder detector).
        return $settings !== null && (bool) $settings->promise_suggestion_detection_enabled;
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
        $cursor = PromiseSuggestionDetectionCursor::lastId(PaymentPromiseSuggestion::SOURCE_CHAT_MESSAGE);
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
                    if ($this->maybeSuggest(
                        PaymentPromiseSuggestion::SOURCE_CHAT_MESSAGE,
                        $message->id,
                        $message->user_id,
                        (string) $message->text,
                    )) {
                        $created++;
                    }
                }
            });

        PromiseSuggestionDetectionCursor::advance(PaymentPromiseSuggestion::SOURCE_CHAT_MESSAGE, $maxId);

        return $created;
    }

    private function scanTelegramMessages(int &$scanned): int
    {
        $cursor = PromiseSuggestionDetectionCursor::lastId(PaymentPromiseSuggestion::SOURCE_TELEGRAM_SUPPORT_MESSAGE);
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
                        continue; // skip unlinked TG — no orphan promises
                    }
                    if ($this->maybeSuggest(
                        PaymentPromiseSuggestion::SOURCE_TELEGRAM_SUPPORT_MESSAGE,
                        $message->id,
                        $userId,
                        (string) $message->text,
                    )) {
                        $created++;
                    }
                }
            });

        PromiseSuggestionDetectionCursor::advance(PaymentPromiseSuggestion::SOURCE_TELEGRAM_SUPPORT_MESSAGE, $maxId);

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

        if (PaymentPromiseSuggestion::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->exists()) {
            return false;
        }

        $extraction = $this->extract($text);
        if ($extraction === null || ! ($extraction['is_payment_deferral'] ?? false)) {
            return false;
        }

        $confidence = (float) ($extraction['confidence'] ?? 0);
        if ($confidence < (float) config('promise_suggestions.confidence_threshold', 0.5)) {
            return false;
        }

        $suggestedDate = null;
        if (! empty($extraction['date'])) {
            try {
                $suggestedDate = Carbon::parse($extraction['date'])->toDateString();
            } catch (\Throwable) {
                $suggestedDate = null;
            }
        }

        $suggestedAmount = null;
        if (isset($extraction['amount']) && $extraction['amount'] !== null && $extraction['amount'] !== '') {
            $suggestedAmount = (float) $extraction['amount'];
        }

        $candidateCourseIds = $this->openDebtCourseIds($userId);
        $courseId = count($candidateCourseIds) === 1 ? $candidateCourseIds[0] : null;

        PaymentPromiseSuggestion::create([
            'user_id' => $userId,
            'course_id' => $courseId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'detected_text' => $extraction['reason'] ?? $text,
            'suggested_date' => $suggestedDate,
            'suggested_amount' => $suggestedAmount,
            'candidate_course_ids' => $candidateCourseIds !== [] ? $candidateCourseIds : null,
            'confidence' => $confidence,
            'status' => PaymentPromiseSuggestion::STATUS_PENDING,
        ]);

        return true;
    }

    /** @return list<int> */
    private function openDebtCourseIds(int $userId): array
    {
        $user = User::query()->find($userId);
        if ($user === null) {
            return [];
        }

        try {
            return $this->debts->forUser($user)
                ->pluck('course_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
        } catch (\Throwable $e) {
            Log::warning('PaymentPromiseDeferralDetector: open debts lookup failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @return array{is_payment_deferral?: bool, date?: ?string, amount?: mixed, courses_hint?: mixed, reason?: ?string, confidence?: float}|null
     */
    private function extract(string $text): ?array
    {
        $prompt = 'Ты классифицируешь сообщение студента школы санскрита. Определи, обещает ли '
            .'студент оплатить позже / просит отсрочку / называет дату оплаты (например «оплачу 20 '
            .'августа», «после зарплаты», «перенесу оплату на понедельник», «will pay by Friday»). '
            .'Верни СТРОГО JSON без пояснений и без markdown-обрамления, формат: '
            .'{"is_payment_deferral": true|false, "date": "YYYY-MM-DD" или null, "amount": число '
            .'или null, "courses_hint": "кратко какой курс если упомянут" или null, '
            .'"reason": "краткая суть на русском" или null, "confidence": 0..1}. '
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
            Log::warning('PaymentPromiseDeferralDetector: failed to parse LLM JSON', ['raw' => $answer]);

            return null;
        }

        return $decoded;
    }
}
