<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Jobs\ComposeStudentQuestionDraft;
use App\Models\Group;
use App\Models\LessonTelegramHook;
use App\Models\MarketingSetting;
use App\Models\TelegramChatPost;
use App\Models\TelegramScheduleReschedule;
use App\Models\TelegramStudentQuestion;
use App\Support\TelegramSendGuard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * H8527 (рулинг MG 21-11: «молчание бота в учебном чате НЕ допускается никогда»).
 *
 * Поверхность СТУДЕНТА (не-менеджера) в чатах групп zapisi:
 *  1) reply-вопрос на пост бота → детерминированная ОДНА строка по типу поста
 *     (ДЗ-пост → что такое ДЗ и где список/сдача; напоминание → ссылка в посте,
 *     записи в кабинете; расписание → ближайшее занятие; нераспознанное →
 *     «вопрос зафиксирован» + запись в стопочку). Опознание поста — по
 *     структурным реестрам LessonTelegramHook / TelegramChatPost /
 *     TelegramScheduleReschedule.receipt_message_id, затем текстовые маркеры.
 *  2) студенческое предложение переноса → ТОЛЬКО стопочка (никогда не
 *     применяется: ADR 0015 + мутационный pin-тест).
 *
 * LLM в чат не ходит: CuratorAi пишет черновик куратору отдельным джобом
 * (ComposeStudentQuestionDraft), в учебный чат уходят только canned-строки.
 */
final class StudentQuestionAckService
{
    private const ACK_CLAIM_TTL_SECONDS = 86400;

    private const HINTS = [
        TelegramStudentQuestion::POST_KIND_HOMEWORK => 'Это пост о приёме ДЗ: само задание появится ответом на этот пост (тег #ДЗ), а список уроков и сдача ДЗ — в кабинете, на странице курса.',
        TelegramStudentQuestion::POST_KIND_REMINDER => 'Это напоминание о занятии: ссылка на подключение — в этом посте, записи занятий — в кабинете.',
        TelegramStudentQuestion::POST_KIND_SCHEDULE => 'Это пост о расписании: актуальные даты — в этом посте, ближайшие занятия — в кабинете.',
        TelegramStudentQuestion::POST_KIND_UNKNOWN => 'Вопрос зафиксировал — куратор скоро ответит.',
    ];

    private const PROPOSAL_HINT = 'Предложение по переносу зафиксировал — передам преподавателю.';

    public function handle(array $message): void
    {
        if (! $this->enabled()) {
            return;
        }

        $from = $message['from'] ?? null;
        if (! is_array($from) || ! empty($from['is_bot'])) {
            return;
        }

        $text = self::normalize($message);
        if ($text === '') {
            return;
        }

        $chatId = $message['chat']['id'] ?? null;
        $messageId = $message['message_id'] ?? null;
        $fromId = $from['id'] ?? null;
        if (! is_numeric($chatId) || ! is_numeric($messageId) || ! is_numeric($fromId)) {
            return;
        }

        $group = Group::query()->where('telegram_chat_id', (string) $chatId)->first();
        if ($group === null || ! $this->groupAllowed($group)) {
            return;
        }

        // Поверхность = опознанный по панели УЧАСТНИК (студент): не staff и
        // связанный с кабинетом (paid-вход в группу по ссылке). Неопознанные
        // отправители молчат, как и сегодня; staff обслуживают свои сервисы.
        $actor = app(TelegramCommandAcl::class)->resolve((int) $fromId);
        if ($actor === null || $this->managesGroup($actor, $group)) {
            return;
        }

        $replyTo = $message['reply_to_message'] ?? null;
        $replyId = is_array($replyTo) ? ($replyTo['message_id'] ?? null) : null;
        $sentAt = Carbon::createFromTimestamp((int) ($message['date'] ?? 0), (string) config('app.timezone', 'Europe/Moscow'));

        if (is_numeric($replyId) && (int) $replyId > 0) {
            $repliedIsBot = is_array($replyTo) && ! empty($replyTo['from']['is_bot']);
            $this->handleReplyQuestion($group, $text, (int) $messageId, $from, (int) $replyId, is_array($replyTo) ? (string) ($replyTo['text'] ?? '') : '', $repliedIsBot, $sentAt);

            return;
        }

        // Без reply: только студенческие предложения переноса идут в стопочку.
        if ($this->isRescheduleProposal($text)) {
            $this->queue($group, (int) $messageId, $from, $text, TelegramStudentQuestion::KIND_PROPOSAL, null, null, null, $sentAt);
            $this->sendAck((string) $chatId, (int) $messageId, self::PROPOSAL_HINT);
        }
    }

    private function handleReplyQuestion(Group $group, string $text, int $messageId, array $from, int $replyId, string $repliedText, bool $repliedIsBot, Carbon $sentAt): void
    {
        $chatId = (string) $group->telegram_chat_id;
        $postKind = $this->classifyRepliedPost($chatId, $replyId, $repliedText, $repliedIsBot);
        if ($postKind === null) {
            // Reply не на пост бота — не наша поверхность (чаты студентов между
            // собой молчанием не лечим и спамить подсказками не хотим).
            return;
        }

        if ($postKind !== TelegramStudentQuestion::POST_KIND_UNKNOWN && $this->isRescheduleProposal($text)) {
            // Предложение переноса в ответ на пост бота: тоже только стопочка.
            $this->queue($group, $messageId, $from, $text, TelegramStudentQuestion::KIND_PROPOSAL, $postKind, $replyId, $repliedText, $sentAt);
            $this->sendAck($chatId, $messageId, self::PROPOSAL_HINT);

            return;
        }

        if ($postKind !== TelegramStudentQuestion::POST_KIND_UNKNOWN) {
            // Тип поста опознан → canned-подсказка одной строкой; стопочка не нужна.
            $this->sendAck($chatId, $messageId, self::HINTS[$postKind]);

            return;
        }

        // Нераспознанный вопрос на пост бота: canned + стопочка + черновик куратору.
        $this->queue($group, $messageId, $from, $text, TelegramStudentQuestion::KIND_QUESTION, $postKind, $replyId, $repliedText, $sentAt);
        $this->sendAck($chatId, $messageId, self::HINTS[$postKind]);
    }

    /**
     * Опознать пост бота, на который отвечает участник. null = это не пост бота
     * (reply между студентами не получает подсказок).
     */
    private function classifyRepliedPost(string $chatId, int $replyId, string $repliedText, bool $repliedIsBot): ?string
    {
        // 1) Структурные реестры — надёжнее текста.
        $hook = LessonTelegramHook::query()
            ->where('telegram_chat_id', $chatId)
            ->where('telegram_message_id', $replyId)
            ->exists();
        if ($hook) {
            return TelegramStudentQuestion::POST_KIND_HOMEWORK;
        }

        $chatPost = TelegramChatPost::query()
            ->where('chat_id', $chatId)
            ->where('message_id', $replyId)
            ->exists();
        if ($chatPost) {
            return TelegramStudentQuestion::POST_KIND_REMINDER;
        }

        $receipt = TelegramScheduleReschedule::query()
            ->where('telegram_chat_id', $chatId)
            ->where('receipt_message_id', $replyId)
            ->exists();
        if ($receipt) {
            return TelegramStudentQuestion::POST_KIND_SCHEDULE;
        }

        // 2) Текстовые маркеры (reply_to_message доезжает из апдейта целиком) —
        //    но только для реально бот-постов (is_bot от Telegram).
        if (! $repliedIsBot) {
            return null;
        }

        $normalized = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $repliedText)));
        if ($normalized === '') {
            return null;
        }

        if (preg_match('/приём дз открыт|прием дз открыт/u', $normalized) === 1) {
            return TelegramStudentQuestion::POST_KIND_HOMEWORK;
        }

        if (preg_match('/скоро занятие|напомин|начнет|начнёт|начнетс|начнётся/u', $normalized) === 1) {
            return TelegramStudentQuestion::POST_KIND_REMINDER;
        }

        if (preg_match('/расписан|перенесено|перенос|отмен/u', $normalized) === 1) {
            return TelegramStudentQuestion::POST_KIND_SCHEDULE;
        }

        return TelegramStudentQuestion::POST_KIND_UNKNOWN;
    }

    private function isRescheduleProposal(string $text): bool
    {
        return preg_match('/перенос|перенес/u', $text) === 1
            && preg_match('/занят|урок|расписан|день|время|дата|\d{1,2}\.\d{1,2}/u', $text) === 1;
    }

    private function queue(
        Group $group,
        int $messageId,
        array $from,
        string $text,
        string $kind,
        ?string $postKind,
        ?int $replyId,
        ?string $repliedText,
        Carbon $sentAt,
    ): void {
        $repliedText = (string) ($repliedText ?? '');
        $question = TelegramStudentQuestion::query()->firstOrCreate(
            [
                'telegram_chat_id' => (string) $group->telegram_chat_id,
                'telegram_message_id' => $messageId,
            ],
            [
                'group_id' => $group->id,
                'author_telegram_id' => (string) ($from['id'] ?? ''),
                'author_name' => trim(((string) ($from['first_name'] ?? '')).' '.((string) ($from['last_name'] ?? ''))) ?: null,
                'author_username' => $from['username'] ?? null,
                'kind' => $kind,
                'post_kind' => $postKind,
                'question_text' => $text,
                'reply_to_message_id' => $replyId,
                'replied_post_excerpt' => $repliedText !== '' ? mb_substr($repliedText, 0, 500) : null,
                'status' => TelegramStudentQuestion::STATUS_NEW,
                'curator_draft_state' => $kind === TelegramStudentQuestion::KIND_QUESTION
                    ? TelegramStudentQuestion::DRAFT_STATE_PENDING
                    : TelegramStudentQuestion::DRAFT_STATE_OFF,
                'sent_at' => $sentAt,
            ],
        );

        if ($question->wasRecentlyCreated && $kind === TelegramStudentQuestion::KIND_QUESTION) {
            ComposeStudentQuestionDraft::dispatch($question->id);
        }
    }

    private function sendAck(string $chatId, int $messageId, string $text): void
    {
        if (! TelegramSendGuard::claimKey('tg:student-question-ack:'.$chatId.':'.$messageId, self::ACK_CLAIM_TTL_SECONDS)) {
            return;
        }

        $token = $this->botToken();
        if ($token === '') {
            Log::warning('StudentQuestionAck: bot token missing');

            return;
        }

        try {
            $response = Http::connectTimeout(5)->timeout(15)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $text,
                'reply_to_message_id' => $messageId,
            ]);
        } catch (\Throwable $exception) {
            Log::warning('StudentQuestionAck: Telegram transport failed', ['error' => $exception->getMessage()]);

            return;
        }

        if (! $response->successful() || ! ($response->json('ok') ?? false)) {
            Log::warning('StudentQuestionAck: Telegram send failed', ['status' => $response->status()]);

            return;
        }

        $id = $response->json('result.message_id');
        if (is_numeric($id)) {
            TelegramStudentQuestion::query()
                ->where('telegram_chat_id', $chatId)
                ->where('telegram_message_id', $messageId)
                ->update(['ack_message_id' => (int) $id]);
        }
    }

    public static function enabled(): bool
    {
        return (bool) config('services.telegram_zapisi.student_question_ack_detect', true);
    }

    private function groupAllowed(Group $group): bool
    {
        // Пустой список = ВСЕ zapisi-группы (рулинг «молчание исключено»);
        // непустой — явный allowlist для точечного раскатывания.
        $allowed = (array) config('services.telegram_zapisi.student_question_group_ids', []);

        return $allowed === [] || in_array($group->id, array_map('intval', $allowed), true);
    }

    /** @param array{role: string, teacher: mixed} $actor */
    private function managesGroup(array $actor, Group $group): bool
    {
        if (TelegramCommandAcl::managesAll($actor['role'])) {
            return true;
        }

        $teacher = $actor['teacher'];

        return $teacher !== null && Group::ledBy($teacher->id)->whereKey($group->id)->exists();
    }

    private function botToken(): string
    {
        $zapisi = (string) (MarketingSetting::cached()?->zapisi_bot_token ?? '');

        return $zapisi !== '' ? $zapisi : (string) (config('services.telegram.student_bot_token')
            ?: config('services.telegram.bot_token') ?: '');
    }

    private static function normalize(array $message): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) ($message['text'] ?? ''))));
    }
}
