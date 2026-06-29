<?php

namespace App\Services\TelegramSupport;

use App\Models\SupportAiReplyEvent;
use App\Models\SupportResponderMapping;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportContact;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;

class TelegramSupportSyncService
{
    public function __construct(
        private readonly SupportConversationAggregator $aggregator,
    ) {}

    public function sync(): array
    {
        if (! config('services.telegram_support.enabled')) {
            return ['status' => 'disabled', 'synced' => 0];
        }

        if (! config('services.telegram_support.api_id') || ! config('services.telegram_support.api_hash')) {
            return ['status' => 'unconfigured', 'synced' => 0];
        }

        if (! class_exists(\danog\MadelineProto\API::class)) {
            return ['status' => 'missing_madelineproto', 'synced' => 0];
        }

        $messages = $this->fetchRecentMadelineMessages();
        if ($messages === []) {
            return ['status' => 'ok', 'synced' => 0, 'dates' => []];
        }

        return $this->syncNormalizedMessages($messages);
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     */
    public function syncNormalizedMessages(array $messages, string $accountName = 'support'): array
    {
        $account = TelegramSupportAccount::updateOrCreate(
            ['name' => $accountName],
            [
                'session_path' => config('services.telegram_support.session'),
                'api_id' => config('services.telegram_support.api_id'),
                'is_enabled' => (bool) config('services.telegram_support.enabled'),
                'last_synced_at' => now(),
            ],
        );

        $affectedDates = collect();
        $synced = 0;

        foreach ($messages as $payload) {
            $message = $this->persistNormalizedMessage($account, $payload);
            $affectedDates->push($message->sent_at->timezone(config('app.timezone'))->toDateString());
            $synced++;
        }

        $affectedDates->unique()->each(fn (string $date) => $this->aggregator->aggregateDate($date));

        return [
            'status' => 'ok',
            'synced' => $synced,
            'dates' => $affectedDates->unique()->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function persistNormalizedMessage(
        TelegramSupportAccount $account,
        array $payload,
    ): TelegramSupportMessage {
        $chatId = (int) $payload['telegram_chat_id'];
        $messageId = (int) $payload['telegram_message_id'];
        $sentAt = CarbonImmutable::parse($payload['sent_at'] ?? now(), config('app.timezone'));
        $direction = (string) ($payload['direction'] ?? 'incoming');
        $telegramUserId = Arr::get($payload, 'telegram_user_id');
        $linkedUser = $telegramUserId
            ? User::where('telegram_id', (string) $telegramUserId)->orWhere('telegram_id', (int) $telegramUserId)->first()
            : null;

        $chat = TelegramSupportChat::firstOrNew(['telegram_chat_id' => $chatId]);
        $chat->fill([
            'linked_user_id' => $chat->linked_user_id ?: $linkedUser?->id,
            'type' => (string) ($payload['chat_type'] ?? $chat->type ?? 'private'),
            'title' => $payload['chat_title'] ?? $chat->title,
            'username' => $payload['chat_username'] ?? $chat->username,
            'first_seen_at' => $chat->first_seen_at
                ? min($chat->first_seen_at, $sentAt)
                : $sentAt,
            'last_message_at' => $chat->last_message_at
                ? max($chat->last_message_at, $sentAt)
                : $sentAt,
        ]);
        $chat->save();

        $contact = $this->upsertContact($chat, $payload, $sentAt, $linkedUser, $direction);
        $responder = $this->resolveResponder($payload, $direction);

        $message = TelegramSupportMessage::updateOrCreate(
            [
                'telegram_support_account_id' => $account->id,
                'telegram_chat_id' => $chatId,
                'telegram_message_id' => $messageId,
            ],
            [
                'telegram_support_chat_id' => $chat->id,
                'telegram_support_contact_id' => $contact?->id,
                'direction' => $direction,
                'role' => $responder['role'],
                'responder_type' => $responder['responder_type'],
                'responder_user_id' => $responder['responder_user_id'],
                'responder_marker' => $responder['responder_marker'],
                'ai_state' => $responder['ai_state'],
                'text' => $payload['text'] ?? null,
                'raw_payload' => $payload,
                'sent_at' => $sentAt,
            ],
        );

        if (in_array($message->ai_state, ['suggested', 'sent'], true)) {
            SupportAiReplyEvent::updateOrCreate(
                [
                    'telegram_support_message_id' => $message->id,
                    'event_type' => $message->ai_state,
                ],
                ['meta' => ['source' => 'telegram_support_sync']],
            );
        }

        return $message;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchRecentMadelineMessages(): array
    {
        $session = (string) config('services.telegram_support.session');
        $settings = [
            'app_info' => [
                'api_id' => (int) config('services.telegram_support.api_id'),
                'api_hash' => (string) config('services.telegram_support.api_hash'),
            ],
        ];

        $client = new \danog\MadelineProto\API($session, $settings);
        $client->start();

        $limit = (int) config('services.telegram_support.history_limit', 50);
        $dialogs = $client->getDialogIds();
        $messages = [];

        foreach ($dialogs as $peer) {
            $history = $client->messages->getHistory([
                'peer' => $peer,
                'offset_id' => 0,
                'offset_date' => 0,
                'add_offset' => 0,
                'limit' => $limit,
                'max_id' => 0,
                'min_id' => 0,
                'hash' => 0,
            ]);

            foreach (($history['messages'] ?? []) as $message) {
                $normalized = $this->normalizeMadelineMessage($peer, $message);
                if ($normalized !== null) {
                    $messages[] = $normalized;
                }
            }
        }

        return $messages;
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>|null
     */
    private function normalizeMadelineMessage(mixed $peer, array $message): ?array
    {
        if (! isset($message['id']) || ! isset($message['date'])) {
            return null;
        }

        $text = $message['message'] ?? null;
        if ($text === null || $text === '') {
            return null;
        }

        $chatId = $this->extractTelegramId($message['peer_id'] ?? $peer);
        if ($chatId === null) {
            return null;
        }

        return [
            'telegram_chat_id' => $chatId,
            'telegram_message_id' => (int) $message['id'],
            'telegram_user_id' => $this->extractTelegramId($message['from_id'] ?? null),
            'direction' => ! empty($message['out']) ? 'outgoing' : 'incoming',
            'text' => $text,
            'sent_at' => CarbonImmutable::createFromTimestamp((int) $message['date'], config('app.timezone'))->toDateTimeString(),
            'raw_madeline' => $message,
        ];
    }

    private function extractTelegramId(mixed $value): ?int
    {
        if (is_int($value) || is_string($value)) {
            return (int) $value;
        }

        if (! is_array($value)) {
            return null;
        }

        foreach (['user_id', 'chat_id', 'channel_id'] as $key) {
            if (isset($value[$key])) {
                return (int) $value[$key];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function upsertContact(
        TelegramSupportChat $chat,
        array $payload,
        CarbonImmutable $sentAt,
        ?User $linkedUser,
        string $direction,
    ): ?TelegramSupportContact {
        $telegramUserId = Arr::get($payload, 'telegram_user_id');

        if (! $telegramUserId && $direction !== 'incoming') {
            return null;
        }

        $contact = $telegramUserId
            ? TelegramSupportContact::firstOrNew(['telegram_user_id' => (int) $telegramUserId])
            : TelegramSupportContact::firstOrNew(['telegram_support_chat_id' => $chat->id]);

        $contact->fill([
            'telegram_support_chat_id' => $chat->id,
            'linked_user_id' => $contact->linked_user_id ?: $linkedUser?->id,
            'name' => $payload['contact_name'] ?? $payload['sender_name'] ?? $contact->name,
            'username' => $payload['contact_username'] ?? $payload['sender_username'] ?? $contact->username,
            'first_seen_at' => $contact->first_seen_at
                ? min($contact->first_seen_at, $sentAt)
                : $sentAt,
            'first_inbound_at' => $direction === 'incoming'
                ? ($contact->first_inbound_at ? min($contact->first_inbound_at, $sentAt) : $sentAt)
                : $contact->first_inbound_at,
        ]);
        $contact->save();

        return $contact;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{role: string, responder_type: ?string, responder_user_id: ?int, responder_marker: ?string, ai_state: ?string}
     */
    private function resolveResponder(array $payload, string $direction): array
    {
        $aiState = $payload['ai_state'] ?? null;
        if (in_array($aiState, ['suggested', 'sent'], true)) {
            return [
                'role' => 'ai',
                'responder_type' => 'ai',
                'responder_user_id' => null,
                'responder_marker' => $payload['responder_marker'] ?? null,
                'ai_state' => $aiState,
            ];
        }

        if ($direction === 'incoming') {
            return [
                'role' => 'user',
                'responder_type' => null,
                'responder_user_id' => null,
                'responder_marker' => null,
                'ai_state' => null,
            ];
        }

        $marker = $payload['responder_marker'] ?? null;
        $mapping = $marker
            ? SupportResponderMapping::where('marker_label', $marker)->where('is_active', true)->first()
            : null;
        $responderUserId = $payload['responder_user_id'] ?? $mapping?->user_id;
        $responderType = $payload['responder_type'] ?? $mapping?->responder_type ?? ($responderUserId ? 'human' : 'unknown');

        return [
            'role' => $responderType === 'human' ? 'human' : 'unknown',
            'responder_type' => $responderType,
            'responder_user_id' => $responderUserId ? (int) $responderUserId : null,
            'responder_marker' => $marker,
            'ai_state' => null,
        ];
    }
}
