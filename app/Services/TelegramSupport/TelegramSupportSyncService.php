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
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramSupportSyncService
{
    public function __construct(
        private readonly SupportConversationAggregator $aggregator,
    ) {}

    public function sync(): array
    {
        if (! config('services.telegram_support.enabled')) {
            Log::info('Telegram support sync skipped', ['status' => 'disabled']);

            return ['status' => 'disabled', 'synced' => 0];
        }

        $account = $this->supportAccount();

        if (! config('services.telegram_support.api_id') || ! config('services.telegram_support.api_hash')) {
            return $this->finish($account, ['status' => 'unconfigured', 'synced' => 0]);
        }

        $clientClass = (string) config('services.telegram_support.client_class');
        if ($clientClass === '' || ! class_exists($clientClass)) {
            return $this->finish($account, ['status' => 'missing_madelineproto', 'synced' => 0]);
        }

        try {
            $messages = $this->fetchIncrementalMadelineMessagesWithRetry($account, $clientClass);
            if ($messages === []) {
                return $this->finish($account, ['status' => 'ok', 'synced' => 0, 'dates' => []], true, []);
            }

            $result = $this->syncNormalizedMessages($messages, $account->name);
            $this->updateSyncState($account->refresh(), $messages);

            return $this->finish($account, $result, true, $messages);
        } catch (Throwable $e) {
            return $this->fail($account, $e);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchIncrementalMadelineMessagesWithRetry(TelegramSupportAccount $account, string $clientClass): array
    {
        try {
            return $this->fetchIncrementalMadelineMessages($account, $clientClass);
        } catch (Throwable $e) {
            if (! $this->isMadelineAuthRestart($e)) {
                throw $e;
            }

            Log::warning('Telegram support sync restarting MadelineProto auth flow after AUTH_RESTART');

            return $this->fetchIncrementalMadelineMessages($account->refresh(), $clientClass);
        }
    }

    private function isMadelineAuthRestart(Throwable $e): bool
    {
        return str_contains($e->getMessage(), 'AUTH_RESTART');
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
    private function fetchIncrementalMadelineMessages(TelegramSupportAccount $account, string $clientClass): array
    {
        $session = (string) config('services.telegram_support.session');
        File::ensureDirectoryExists(dirname(base_path($session)));

        $settings = $this->madelineSettings();

        $client = new $clientClass($session, $settings);
        $client->start();

        $limit = (int) config('services.telegram_support.history_limit', 50);
        $dialogs = $this->limitedDialogs($client->getDialogIds());
        $messages = [];
        $peerState = $account->sync_state['peers'] ?? [];

        foreach ($dialogs as $peer) {
            $peerId = $this->extractTelegramId($peer);
            $cursor = $peerId ? ($peerState[(string) $peerId] ?? []) : [];
            $minId = (int) ($cursor['last_message_id'] ?? 0);

            $history = $client->messages->getHistory([
                'peer' => $peer,
                'offset_id' => 0,
                'offset_date' => 0,
                'add_offset' => 0,
                'limit' => $limit,
                'max_id' => 0,
                'min_id' => $minId,
                'hash' => 0,
            ]);

            foreach (($history['messages'] ?? []) as $message) {
                $normalized = $this->normalizeMadelineMessage($peer, $message);
                if ($normalized !== null && (int) $normalized['telegram_message_id'] > $minId) {
                    $messages[] = $normalized;
                }
            }
        }

        return collect($messages)
            ->sortBy([
                ['sent_at', 'asc'],
                ['telegram_message_id', 'asc'],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, mixed>  $dialogs
     * @return array<int, mixed>
     */
    private function limitedDialogs(array $dialogs): array
    {
        $limit = (int) config('services.telegram_support.dialog_limit', 20);

        if ($limit <= 0) {
            return $dialogs;
        }

        return array_slice($dialogs, 0, $limit);
    }

    private function madelineSettings(): object
    {
        $settingsClass = 'danog\\MadelineProto\\Settings';
        $settings = new $settingsClass;
        $settings->getAppInfo()
            ->setApiId((int) config('services.telegram_support.api_id'))
            ->setApiHash((string) config('services.telegram_support.api_hash'));

        return $settings;
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

    private function supportAccount(string $accountName = 'support'): TelegramSupportAccount
    {
        return TelegramSupportAccount::firstOrCreate(
            ['name' => $accountName],
            [
                'session_path' => config('services.telegram_support.session'),
                'api_id' => config('services.telegram_support.api_id'),
                'is_enabled' => (bool) config('services.telegram_support.enabled'),
            ],
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     */
    private function updateSyncState(TelegramSupportAccount $account, array $messages): void
    {
        $state = $account->sync_state ?? [];
        $state['peers'] ??= [];

        foreach ($messages as $message) {
            $peerKey = (string) $message['telegram_chat_id'];
            $current = $state['peers'][$peerKey] ?? [];
            $messageId = (int) $message['telegram_message_id'];

            if ($messageId >= (int) ($current['last_message_id'] ?? 0)) {
                $state['peers'][$peerKey] = [
                    'last_message_id' => $messageId,
                    'last_sent_at' => $message['sent_at'],
                ];
            }
        }

        $account->forceFill([
            'sync_state' => $state,
            'last_successful_sync_at' => now(),
            'last_sync_error' => null,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<string, mixed>
     */
    private function finish(
        TelegramSupportAccount $account,
        array $result,
        bool $successful = false,
        array $messages = [],
    ): array {
        $account->forceFill([
            'session_path' => config('services.telegram_support.session'),
            'api_id' => config('services.telegram_support.api_id'),
            'is_enabled' => (bool) config('services.telegram_support.enabled'),
            'last_synced_at' => now(),
            'last_successful_sync_at' => $successful ? now() : $account->last_successful_sync_at,
            'last_sync_error' => $successful ? null : $account->last_sync_error,
        ])->save();

        Log::info('Telegram support sync finished', [
            'status' => $result['status'] ?? 'unknown',
            'synced' => $result['synced'] ?? 0,
            'dates' => $result['dates'] ?? [],
            'messages_seen' => count($messages),
        ]);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function fail(TelegramSupportAccount $account, Throwable $e): array
    {
        $account->forceFill([
            'last_synced_at' => now(),
            'last_sync_error' => $e->getMessage(),
        ])->save();

        Log::error('Telegram support sync failed', [
            'error' => $e->getMessage(),
            'exception' => $e::class,
        ]);

        return [
            'status' => 'error',
            'synced' => 0,
            'error' => $e->getMessage(),
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
