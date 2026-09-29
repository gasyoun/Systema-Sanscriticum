<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\SupportConversation;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Support\SupportSmallTalk;

/**
 * После ingest TelegramSupportMessage: открыть Helpdesk-тред и при match
 * пометить queue=technical + assigned_to техспециалисту.
 *
 * Политика v1:
 *  - private: любой incoming → тред (general или technical);
 *  - group/supergroup: тред только если TechnicalIssueDetector::matches.
 */
class TechnicalIssueRouter
{
    /** Служебные уведомления самого Telegram (вход с нового устройства, передача группы). */
    public const TELEGRAM_SERVICE_CHAT_ID = 777000;

    public function __construct(
        private readonly TechnicalIssueDetector $detector,
        private readonly SupportConversationManager $conversations,
        private readonly TechnicalIssueNotifier $notifier,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handleIncoming(
        TelegramSupportMessage $message,
        array $payload,
        ?int $linkedUserId,
        string $chatType,
    ): ?SupportConversation {
        if (($payload['direction'] ?? $message->direction) !== 'incoming') {
            return null;
        }

        $isPrivate = $chatType === 'private';
        $isTech = $this->detector->matches($payload);

        if (! $isPrivate && ! $isTech) {
            return null;
        }

        // Автор не привязан к аккаунту — раньше вопрос молча терялся. Ни приём, ни
        // ответ привязки не требуют (юзербот читает и отвечает по telegram_chat_id),
        // поэтому заводим тред без user_id — тем же приёмом, что у гостей веб-виджета.
        //
        // Но только для ТЕХНИЧЕСКИХ вопросов, и в группе, и в личке: болтовню
        // незнакомца в личке по-прежнему пропускаем, иначе тред заводился бы на
        // каждого постороннего, написавшего юзерботу «привет».
        //
        // Флаг support_unlinked_dm_threads (24-09-2026): личка незнакомца с
        // СОДЕРЖАТЕЛЬНЫМ сообщением тоже заводит тред. Иначе пробные заявки и
        // «куда внести оплату» от ещё не привязанных людей видны лишь в read-only
        // «Аналитике» и куратор не может ответить из Helpdesk. Чистое «привет» /
        // «спасибо» по-прежнему пропускаем.
        $isLinked = $linkedUserId && User::query()->whereKey($linkedUserId)->exists();

        if (! $isLinked && ! $isTech && ! $this->opensUnlinkedDmThread($isPrivate, $payload, $message)) {
            return null;
        }

        $chatId = (int) ($payload['telegram_chat_id'] ?? $message->telegram_chat_id);

        $thread = $isLinked
            ? $this->conversations->recordMessage($linkedUserId, $message, $message->sent_at)
            : $this->openUnlinkedThread($message, $payload, $chatId);

        $updates = [
            'source_telegram_chat_id' => (int) ($payload['telegram_chat_id'] ?? $message->telegram_chat_id),
            'source_telegram_message_id' => (int) ($payload['telegram_message_id'] ?? $message->telegram_message_id),
            'source_chat_type' => $chatType,
        ];

        // Уведомляем один раз — на переходе в очередь «Техника». Иначе колокольчик
        // звенел бы на каждое следующее сообщение того же треда.
        $becameTechnical = $isTech && $thread->queue !== SupportConversation::QUEUE_TECHNICAL;

        if ($isTech) {
            $updates['queue'] = SupportConversation::QUEUE_TECHNICAL;
            $assigneeId = config('support_tech.assignee_user_id')
                ?? config('services.telegram_support.tech_assignee_user_id');
            if ($assigneeId && User::query()->whereKey((int) $assigneeId)->exists()) {
                $updates['assigned_to'] = (int) $assigneeId;
            }
        } elseif ($isPrivate && $thread->queue !== SupportConversation::QUEUE_TECHNICAL) {
            // Не даунгрейдить уже technical-тред.
            $updates['queue'] = SupportConversation::QUEUE_GENERAL;
        }

        $thread->forceFill($updates)->save();
        $thread->refresh();

        if ($becameTechnical) {
            $this->notifier->newTechnicalIssue($thread, (string) ($payload['text'] ?? $message->text ?? ''));
        }

        return $thread;
    }

    /**
     * Личка непривязанного автора заводит тред, если флаг ON и это не чистый
     * small talk. Пустой текст (фото чека, стикер, файл) — тоже тред: чек
     * об оплате без подписи куратор обязан увидеть.
     *
     * @param  array<string, mixed>  $payload
     */
    private function opensUnlinkedDmThread(bool $isPrivate, array $payload, TelegramSupportMessage $message): bool
    {
        if (! $isPrivate || ! config('features.support_unlinked_dm_threads', false)) {
            return false;
        }

        // «Незавершённая попытка входа», «Group Transferred» — это не человек.
        $chatId = (int) ($payload['telegram_chat_id'] ?? $message->telegram_chat_id);
        if ($chatId === self::TELEGRAM_SERVICE_CHAT_ID) {
            return false;
        }

        return SupportSmallTalk::kind((string) ($payload['text'] ?? $message->text ?? '')) === null;
    }

    /**
     * Тред техвопроса от непривязанного автора: ключ — пара «чат + автор», имя
     * берём из самого сообщения, чтобы в Helpdesk был человек, а не «Гость #17».
     *
     * @param  array<string, mixed>  $payload
     */
    private function openUnlinkedThread(TelegramSupportMessage $message, array $payload, int $chatId): SupportConversation
    {
        $telegramUserId = $payload['telegram_user_id'] ?? null;
        $author = trim((string) ($payload['contact_name'] ?? ''));
        $username = trim((string) ($payload['contact_username'] ?? ''));
        $chatTitle = trim((string) ($payload['chat_title'] ?? ''));

        if ($author === '') {
            $author = $username !== '' ? '@'.$username : 'Аноним';
        }

        // «Иван (Хинди гр.1)» — куратору сразу видно, кто и откуда спрашивает.
        $displayName = $chatTitle !== '' ? "{$author} ({$chatTitle})" : $author;

        $thread = $this->conversations->openForTelegramChat(
            $chatId,
            $telegramUserId !== null ? (int) $telegramUserId : null,
            $displayName,
        );

        $this->conversations->attach($thread, $message, $message->sent_at);

        return $thread;
    }
}
