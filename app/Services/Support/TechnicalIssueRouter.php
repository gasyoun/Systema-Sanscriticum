<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\SupportConversation;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use Illuminate\Support\Facades\Log;

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
    public function __construct(
        private readonly TechnicalIssueDetector $detector,
        private readonly SupportConversationManager $conversations,
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

        if (! $linkedUserId) {
            Log::info('TechnicalIssueRouter: skip ops — sender not linked to User', [
                'telegram_chat_id' => $payload['telegram_chat_id'] ?? $message->telegram_chat_id,
                'telegram_message_id' => $payload['telegram_message_id'] ?? $message->telegram_message_id,
                'is_technical' => $isTech,
                'chat_type' => $chatType,
            ]);

            return null;
        }

        // Убедиться, что user ещё существует (assignee/FK).
        if (! User::query()->whereKey($linkedUserId)->exists()) {
            return null;
        }

        $thread = $this->conversations->recordMessage($linkedUserId, $message, $message->sent_at);

        $updates = [
            'source_telegram_chat_id' => (int) ($payload['telegram_chat_id'] ?? $message->telegram_chat_id),
            'source_telegram_message_id' => (int) ($payload['telegram_message_id'] ?? $message->telegram_message_id),
            'source_chat_type' => $chatType,
        ];

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

        return $thread->refresh();
    }
}
